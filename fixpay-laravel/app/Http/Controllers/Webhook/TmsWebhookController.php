<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Models\NinePsbTransaction;
use App\Models\RiskAssessment;
use App\Models\Transfer;
use App\Models\VtpassPayment;
use App\Services\Risk\RiskTagService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives TMS screening-result webhooks (delivered by TMS DeliverWebhookJob).
 *
 * Verifies the HMAC signature (X-TMS-Signature: sha256=...) and applies the
 * AML result to the matching fixpay entity, tagging it and raising an alert
 * when flagged. Returns HTTP 2xx so TMS marks the delivery as successful.
 */
class TmsWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.tms.webhook_secret');
        $signature = (string) $request->header('X-TMS-Signature');
        $body = $request->getContent();

        $expected = 'sha256='.hash_hmac('sha256', $body, $secret);

        if ($secret === '' || ! hash_equals($expected, $signature)) {
            Log::warning('TMS webhook: invalid signature', [
                'ip'        => $request->ip(),
                'call_ref'  => $request->header('X-TMS-Call-Ref'),
                'event'     => $request->header('X-TMS-Event'),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $payload = $request->json()->all();

        $assessable = $this->resolveAssessable($payload['client_reference'] ?? null, $payload);

        if (! $assessable) {
            Log::warning('TMS webhook: unresolvable client_reference', ['payload' => $payload]);

            return response()->json(['message' => 'Unknown client reference.'], 404);
        }

        /** @var RiskTagService $riskTags */
        $riskTags = app(RiskTagService::class);

        $callRef = (string) ($payload['call_ref'] ?? $request->header('X-TMS-Call-Ref', ''));

        $assessment = $callRef !== ''
            ? $assessable->riskAssessments()
                ->where('type', 'AML')
                ->where('tms_call_ref', $callRef)
                ->first()
            : null;

        if ($assessment) {
            $riskTags->applyAmlResult($assessment, $payload);
        } else {
            $riskTags->recordAssessment(
                assessable: $assessable,
                type: 'AML',
                status: 'CLEAR',
                payload: $payload,
                callRef: $callRef !== '' ? $callRef : null,
            );
        }

        Log::info('TMS webhook: AML result applied', [
            'entity_type' => $assessable->getMorphClass(),
            'entity_id'   => $assessable->getKey(),
            'call_ref'    => $callRef,
        ]);

        return response()->json(['status' => 'ok']);
    }

    private function resolveAssessable(?string $clientReference, array $payload): ?Model
    {
        if ($clientReference !== null && str_starts_with($clientReference, 'user:')) {
            return AppUser::find(substr($clientReference, 5));
        }

        if ($clientReference !== null && str_starts_with($clientReference, 'transaction:')) {
            $id = substr($clientReference, 12);

            foreach ([Transfer::class, VtpassPayment::class, NinePsbTransaction::class] as $model) {
                $found = $model::find($id);
                if ($found) {
                    return $found;
                }
            }
        }

        // Fallback: look up by stored assessable via the call reference.
        $callRef = $payload['call_ref'] ?? null;

        if ($callRef) {
            $assessment = RiskAssessment::where('tms_call_ref', $callRef)->first();

            if ($assessment && $assessment->assessable) {
                return $assessment->assessable;
            }
        }

        return null;
    }
}
