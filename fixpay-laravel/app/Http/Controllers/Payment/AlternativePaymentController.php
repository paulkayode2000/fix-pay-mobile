<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\AlternativePayment;
use App\Services\Payment\AlternativePaymentService;
use App\Support\BillAmounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Bill payments on a non-wallet rail (Payfixy / card / transfer / USSD).
 *
 * Every request is a normal merchant bill payment to the Payfixy Gateway
 * (`/api/v1/mobile/bills/pay` + `payment_method`): the gateway picks the rail
 * and settles the bill. No wallet — and therefore no wallet hold — is
 * involved, so a user who has never opened a wallet can pay a bill.
 */
class AlternativePaymentController extends Controller
{
    public function __construct(
        private readonly AlternativePaymentService $alternative,
    ) {}

    /** POST /api/payments/alternative/initiate */
    public function initiate(Request $request): JsonResponse
    {
        if (! config('services.gateway.enabled', false)) {
            return response()->json([
                'message' => 'Alternative payment methods are not available at this time.',
            ], 503);
        }

        $data = $request->validate([
            'service_id'        => 'required|string',
            'amount_kobo'       => BillAmounts::rules($request->input('service_id')),
            'phone'             => 'required|string',
            'payment_method'    => 'nullable|string|in:'.implode(',', AlternativePaymentService::SUPPORTED_METHODS),
            'billers_code'      => 'nullable|string',
            'variation_code'    => 'nullable|string',
            'subscription_type' => 'nullable|in:renew,change',
            'idempotency_key'   => 'nullable|string|uuid',
        ]);

        $user = $request->user();
        $wallet = $user->wallet;

        $payment = $this->alternative->initiate(
            user: $user,
            // A wallet is OPTIONAL here: it is only recorded when the user
            // happens to have an active one, never required to pay.
            wallet: ($wallet && $wallet->status === 'ACTIVE') ? $wallet : null,
            serviceId: $data['service_id'],
            amountKobo: $data['amount_kobo'],
            phone: $data['phone'],
            billersCode: $data['billers_code'] ?? null,
            variationCode: $data['variation_code'] ?? null,
            paymentMethod: $data['payment_method']
                ?? config('services.gateway.alternative_payment_method', 'payfixy'),
            extra: [
                'idempotency_key'   => $data['idempotency_key'] ?? null,
                'subscription_type' => $data['subscription_type'] ?? null,
            ],
        );

        // Asynchronous TMS checks (antifraud + AML) — tag the payment when done.
        try {
            \App\Jobs\AntifraudScoreJob::dispatch($payment, \App\Support\DeviceId::fromRequest());
            \App\Jobs\TransactionAmlScreenJob::dispatch($payment);
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch TMS risk jobs for alternative payment', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json(
            $this->present($payment),
            $payment->payment_status === 'FAILED' ? 422 : 200,
        );
    }

    /**
     * POST /api/payments/alternative/verify
     *
     * The gateway settles this rail synchronously, so verification is a
     * user-scoped read of the recorded outcome — never a client-supplied
     * status. A payment still PROCESSING (ambiguous gateway outcome) is
     * reported as pending until the gateway reference resolves it.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gateway_reference' => 'required|string',
        ]);

        $payment = AlternativePayment::where('user_id', $request->user()->id)
            ->where(function ($q) use ($data) {
                $q->where('gateway_reference', $data['gateway_reference'])
                    ->orWhere('payment_reference', $data['gateway_reference']);
            })
            ->latest('created_at')
            ->first();

        if (! $payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        return response()->json($this->present($payment));
    }

    /**
     * Client-facing shape of an alternative payment.
     *
     * `status` is the raw payment_status so the client can map it the same way
     * it maps VTPass statuses; `vtpass_code` mirrors `provider_code` for the
     * existing code-lookup helpers.
     *
     * @return array<string, mixed>
     */
    private function present(AlternativePayment $payment): array
    {
        return [
            'payment_reference' => $payment->payment_reference,
            'gateway_reference' => $payment->gateway_reference,
            'status'            => $payment->payment_status,
            'payment_status'    => $payment->payment_status,
            'payment_method'    => $payment->payment_method,
            'service_id'        => $payment->service_id,
            'variation_code'    => $payment->variation_code,
            'amount_kobo'       => $payment->amount_kobo,
            'fee_kobo'          => $payment->fee_kobo,
            'phone'             => $payment->phone,
            'billers_code'      => $payment->billers_code,
            'provider_code'     => $payment->provider_code,
            'vtpass_code'       => $payment->provider_code,
            'token'             => $payment->token,
            'units'             => $payment->units,
            'completed_at'      => $payment->completed_at,
            'failed_at'         => $payment->failed_at,
            'message'           => $payment->payment_status === 'FAILED'
                ? 'Payment failed. Please try again.'
                : null,
        ];
    }
}
