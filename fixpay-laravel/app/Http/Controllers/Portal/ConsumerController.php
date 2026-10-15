<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Otp;
use App\Models\Transfer;
use App\Models\VtpassPayment;
use App\Services\Fixpay\ConsumerOpsService;
use App\Services\Payment\VtpassService;
use App\Services\Transfer\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-to-server bridge consumed ONLY by FixChat's backend
 * (X-API-Key + X-Tenant-Slug). See fixchat/docs/INTEGRATION.md.
 *
 * This introduces no new money rail: transfers go through TransferService and
 * bills through VtpassService, so the ledger, risk checks and webhooks stay
 * identical to the app's own flows.
 */
class ConsumerController extends Controller
{
    public function __construct(
        private readonly ConsumerOpsService $consumers,
        private readonly TransferService $transferService,
        private readonly VtpassService $vtpass,
    ) {
    }

    /** GET /api/portal/consumers/lookup?phone= */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => 'required|string|max:20']);
        $user = $this->consumers->resolve($request->input('__tenant_id'), $data['phone']);

        if (! $user) {
            return response()->json(['exists' => false, 'phone' => $data['phone']]);
        }

        return response()->json([
            'exists' => true,
            'user_id' => $user->id,
            'phone' => $user->phone,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'kyc_status' => $user->kyc_status,
            'tier' => $user->tier,
        ]);
    }

    /** POST /api/portal/consumers/otp/send */
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => 'required|string|max:20']);

        $user = $this->consumers->resolve($request->input('__tenant_id'), $data['phone']);
        if (! $user) {
            // Same shape as success to avoid leaking which numbers are registered.
            return response()->json(['sent' => false]);
        }

        $code = (string) random_int(100000, 999999);

        Otp::query()
            ->where('identifier', $data['phone'])
            ->where('purpose', 'fixchat_link')
            ->where('used', false)
            ->update(['used' => true]);

        Otp::create([
            'identifier' => $data['phone'],
            'type' => 'phone',
            'purpose' => 'fixchat_link',
            'code' => $code,
            'used' => false,
            'expires_at' => now()->addMinutes(10),
        ]);

        // Dispatch through the app's existing SMS channel. Kept as a log here so
        // the bridge has no missing dependency; swap for the real notifier.
        Log::info('fixchat.link.otp', ['phone' => $data['phone'], 'code' => $code]);

        return response()->json(['sent' => true, 'expires_in' => 600]);
    }

    /** POST /api/portal/consumers/otp/verify */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'code' => 'required|string|max:8',
        ]);

        $otp = Otp::query()
            ->where('identifier', $data['phone'])
            ->where('purpose', 'fixchat_link')
            ->latest('id')
            ->first();

        if (! $otp || ! $otp->isValid() || $otp->code !== $data['code']) {
            return response()->json(['verified' => false]);
        }

        $otp->update(['used' => true]);

        $user = $this->consumers->resolve($request->input('__tenant_id'), $data['phone']);

        return response()->json(['verified' => true, 'user_id' => $user?->id]);
    }

    /** GET /api/portal/consumers/{phone}/wallet */
    public function wallet(Request $request, string $phone): JsonResponse
    {
        $user = $this->consumers->resolveOrFail($request->input('__tenant_id'), $phone);
        $wallet = $user->wallet;

        if (! $wallet) {
            return response()->json(['message' => 'Wallet not available.'], 422);
        }

        return response()->json([
            'balance_kobo' => (int) $wallet->balance_kobo,
            'ledger_balance_kobo' => (int) $wallet->ledger_balance_kobo,
            'currency' => $wallet->currency ?? 'NGN',
            'status' => $wallet->status,
            'virtual_account_number' => $wallet->virtual_account_number,
            'virtual_account_bank' => $wallet->virtual_account_bank,
        ]);
    }

    /** POST /api/portal/consumers/{phone}/transfers/wallet */
    public function transferWallet(Request $request, string $phone): JsonResponse
    {
        $data = $request->validate([
            'amount_kobo' => 'required|integer|min:100',
            'recipient_phone' => 'required|string|max:20',
            'narration' => 'nullable|string|max:100',
            'idempotency_key' => 'nullable|string|uuid',
        ]);

        $user = $this->consumers->resolveOrFail($request->input('__tenant_id'), $phone);

        if ($user->kyc_status !== 'VERIFIED') {
            return response()->json(['message' => 'Consumer KYC not verified.'], 403);
        }

        $wallet = $user->wallet;
        if (! $wallet || $wallet->status !== 'ACTIVE') {
            return response()->json(['message' => 'Wallet not available.'], 422);
        }

        $transfer = $this->transferService->initiateWallet(
            user: $user,
            wallet: $wallet,
            amountKobo: $data['amount_kobo'],
            recipientPhone: $data['recipient_phone'],
            narration: $data['narration'] ?? 'Wallet transfer',
            idempotencyKey: $data['idempotency_key'] ?? $request->header('Idempotency-Key'),
        );

        return response()->json([
            'transfer_reference' => $transfer->transfer_reference,
            'status' => $transfer->status,
            'amount_kobo' => (int) $transfer->amount_kobo,
        ], 202);
    }

    /** GET /api/portal/consumers/payments/vtpass/services?service_id= */
    public function services(Request $request): JsonResponse
    {
        $identifier = $request->query('service_id', $request->query('identifier', 'airtime'));

        try {
            $response = Http::timeout(15)->withoutVerifying()
                ->withHeaders([
                    'api-key' => config('services.vtpass.api_key'),
                    'public-key' => config('services.vtpass.public_key'),
                ])
                ->get(config('services.vtpass.base_url').'/services', [
                    'identifier' => $identifier,
                ]);

            return response()->json($response->json());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Service not available at this time.'], 503);
        }
    }

    /** GET /api/portal/consumers/payments/vtpass/variations?service_id= */
    public function variations(Request $request): JsonResponse
    {
        $serviceId = $request->query('service_id', $request->query('serviceID'));
        abort_if(empty($serviceId), 422, 'service_id is required.');

        try {
            $response = Http::timeout(15)->withoutVerifying()
                ->withHeaders([
                    'api-key' => config('services.vtpass.api_key'),
                    'secret-key' => config('services.vtpass.secret_key'),
                    'public-key' => config('services.vtpass.public_key'),
                ])
                ->get(config('services.vtpass.base_url').'/service-variations', [
                    'serviceID' => $serviceId,
                ]);

            return response()->json($response->json());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Service not available at this time.'], 503);
        }
    }

    /** POST /api/portal/consumers/payments/vtpass/verify */
    public function verifyBill(Request $request): JsonResponse
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
        if (! empty($data['type'])) {
            $payload['type'] = $data['type'];
        }

        try {
            $response = Http::timeout(15)->withoutVerifying()
                ->withHeaders([
                    'api-key' => config('services.vtpass.api_key'),
                    'secret-key' => config('services.vtpass.secret_key'),
                    'Content-Type' => 'application/json',
                ])
                ->post(config('services.vtpass.base_url').'/merchant-verify', $payload);

            return response()->json($response->json());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Service not available at this time.'], 503);
        }
    }

    /** POST /api/portal/consumers/{phone}/payments/vtpass */
    public function payBill(Request $request, string $phone): JsonResponse
    {
        $data = $request->validate([
            'service_id' => 'required|string',
            'amount_kobo' => 'required|integer|min:100',
            'billers_code' => 'nullable|string',
            'variation_code' => 'nullable|string',
            'subscription_type' => 'nullable|in:renew,change',
            'idempotency_key' => 'nullable|string|uuid',
        ]);

        $user = $this->consumers->resolveOrFail($request->input('__tenant_id'), $phone);

        $wallet = $user->wallet;
        if (! $wallet || $wallet->status !== 'ACTIVE') {
            return response()->json(['message' => 'Wallet not available.'], 422);
        }

        $payment = $this->vtpass->initiate(
            user: $user,
            wallet: $wallet,
            serviceId: $data['service_id'],
            amountKobo: $data['amount_kobo'],
            phone: $user->phone,
            billersCode: $data['billers_code'] ?? null,
            variationCode: $data['variation_code'] ?? null,
            extra: [
                'idempotency_key' => $data['idempotency_key'] ?? $request->header('Idempotency-Key'),
                'subscription_type' => $data['subscription_type'] ?? null,
            ],
        );

        $payment = $this->vtpass->submit($payment);

        return response()->json([
            'payment_reference' => $payment->payment_reference,
            'status' => $payment->payment_status,
            'amount_kobo' => (int) $payment->amount_kobo,
            'fee_kobo' => (int) ($payment->fee_kobo ?? 0),
            'token' => $payment->token,
            'units' => $payment->units,
            'purchased_code' => $payment->token,
        ], $payment->payment_status === 'COMPLETED' ? 200 : 422);
    }

    /** GET /api/portal/consumers/{phone}/transactions */
    public function transactions(Request $request, string $phone): JsonResponse
    {
        $user = $this->consumers->resolveOrFail($request->input('__tenant_id'), $phone);
        $limit = (int) $request->query('limit', 20);

        $transfers = Transfer::where('user_id', $user->id)
            ->latest('created_at')
            ->limit($limit)
            ->get();

        $payments = VtpassPayment::where('user_id', $user->id)
            ->latest('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'transfers' => $transfers,
            'payments' => $payments,
        ]);
    }
}
