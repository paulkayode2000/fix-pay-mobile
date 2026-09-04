<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payment\VtpassService;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VtpassPaymentController extends Controller
{
    public function __construct(
        private readonly VtpassService $vtpass,
    ) {}

    /** GET /api/payments/vtpass/services */
    public function services(Request $request): JsonResponse
    {
        try {
            $identifier = $request->query('identifier', 'airtime');
            $response = \Illuminate\Support\Facades\Http::timeout(15)->withoutVerifying()
                ->withHeaders([
                    'api-key' => config('services.vtpass.api_key'),
                    'public-key' => config('services.vtpass.public_key'),
                ])
                ->get(config('services.vtpass.base_url') . '/services', [
                    'identifier' => $identifier
                ]);

            return response()->json($response->json());
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Service not available at this time. Please try again later.'
            ], 503);
        }
    }

    /** GET /api/payments/vtpass/variations */
    public function variations(Request $request): JsonResponse
    {
        $request->validate(['serviceID' => 'required|string']);

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)->withoutVerifying()
                ->withHeaders([
                    'api-key' => config('services.vtpass.api_key'),
                    'secret-key' => config('services.vtpass.secret_key'),
                    'public-key' => config('services.vtpass.public_key'),
                ])
                ->get(config('services.vtpass.base_url') . '/service-variations', [
                    'serviceID' => $request->query('serviceID')
                ]);

            return response()->json($response->json());
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Service not available at this time. Please try again later.'
            ], 503);
        }
    }

    /** POST /api/payments/verify */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id' => 'required|string',
            'billers_code' => 'required|string',
            'type' => 'nullable|string',
        ]);

        $payload = [
            'serviceID' => $data['service_id'],
            'billersCode' => $data['billers_code'],
        ];
        if (isset($data['type'])) {
            $payload['type'] = $data['type'];
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)->withoutVerifying()
                ->withHeaders([
                    'api-key' => config('services.vtpass.api_key'),
                    'secret-key' => config('services.vtpass.secret_key'),
                    'Content-Type' => 'application/json',
                ])
                ->post(config('services.vtpass.base_url') . '/merchant-verify', $payload);

            return response()->json($response->json());
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Service not available at this time. Please try again later.'
            ], 503);
        }
    }

    /** POST /api/payments/vtpass */
    public function pay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id'        => 'required|string',
            'amount_kobo'       => 'required|integer|min:100',
            'phone'             => 'required|string',
            'billers_code'      => 'nullable|string',
            'variation_code'    => 'nullable|string',
            'subscription_type' => 'nullable|in:renew,change',
            'idempotency_key'   => 'nullable|string|uuid',
        ]);

        $user = $request->user();
        $wallet = $user->wallet;

        if (! $wallet || $wallet->status !== 'ACTIVE') {
            return response()->json(['message' => 'Wallet not available.'], 422);
        }

        $payment = $this->vtpass->initiate(
            user: $user,
            wallet: $wallet,
            serviceId: $data['service_id'],
            amountKobo: $data['amount_kobo'],
            phone: $data['phone'],
            billersCode: $data['billers_code'] ?? null,
            variationCode: $data['variation_code'] ?? null,
            extra: [
                'idempotency_key'   => $data['idempotency_key'] ?? null,
                'subscription_type' => $data['subscription_type'] ?? null,
            ],
        );

        // Asynchronous TMS checks (antifraud + AML) — tag the payment when done.
        try {
            \App\Jobs\AntifraudScoreJob::dispatch($payment, request()->header('X-Device-Id', ''));
            \App\Jobs\TransactionAmlScreenJob::dispatch($payment);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to dispatch TMS risk jobs for payment', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);
        }

        try {
            // Submit asynchronously via queue in production; sync here for simplicity
            $payment = $this->vtpass->submit($payment);

            return response()->json([
                'payment_reference' => $payment->payment_reference,
                'status' => $payment->payment_status,
                'token' => $payment->token,
                'units' => $payment->units,
                'amount_kobo' => $payment->amount_kobo,
                'fee_kobo' => $payment->fee_kobo,
                'provider_code' => $payment->provider_code,
                'vtpass_code' => $payment->provider_code,
                'message' => $payment->payment_status === 'FAILED' ? ($payment->response_payload['response_description'] ?? 'Transaction failed.') : null,
            ], $payment->payment_status === 'COMPLETED' ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Service not available at this time. Please try again later.',
                'payment_reference' => $payment->payment_reference,
                'status' => 'FAILED',
            ], 503);
        }
    }

    /** GET /api/payments/vtpass/{reference} */
    public function status(Request $request, string $reference): JsonResponse
    {
        $payment = \App\Models\VtpassPayment::where('payment_reference', $reference)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'payment_reference' => $payment->payment_reference,
            'service_id' => $payment->service_id,
            'amount_kobo' => $payment->amount_kobo,
            'fee_kobo' => $payment->fee_kobo,
            'status' => $payment->payment_status,
            'token' => $payment->token,
            'units' => $payment->units,
            'provider_code' => $payment->provider_code,
            'vtpass_code' => $payment->provider_code,
            'phone' => $payment->phone,
            'billers_code' => $payment->billersCode,
            'variation_code' => $payment->variation_code,
            'completed_at' => $payment->completed_at,
            'failed_at' => $payment->failed_at,
        ]);
    }
}
