<?php

namespace App\Services\Payment;

use App\Models\AlternativePayment;
use App\Models\AppUser;
use App\Models\Wallet;
use App\Services\Gateway\GatewayClient;
use App\Services\Gateway\GatewayRequestException;
use Illuminate\Support\Str;

/**
 * Bill payments on a non-wallet rail.
 *
 * The user pays through the Payfixy Gateway's normal merchant bill route
 * (`/api/v1/mobile/bills/pay` with a `payment_method`), so NO wallet is
 * required: the gateway picks the rail (Payfixy checkout, card, transfer,
 * USSD …) and settles the bill with the provider. Because no wallet hold is
 * taken, nothing is reserved locally — the gateway's verdict is the outcome.
 *
 * Wallet-funded payments keep going through VtpassService (wallet hold →
 * commit/release) and are recorded in `vtpass_payments`. This service records
 * its own `alternative_payments` rows so the two money models never mix.
 */
class AlternativePaymentService
{
    public function __construct(
        private readonly GatewayClient $gatewayClient,
        private readonly PaymentRailService $railService,
    ) {}

    /** Payment method values the gateway accepts on the alternative rail. */
    public const SUPPORTED_METHODS = ['payfixy', 'card', 'bank_transfer', 'ussd'];

    /**
     * Initiate (and, for this rail, settle) a bill payment through the gateway.
     *
     * @param  array{idempotency_key?:string|null, subscription_type?:string|null}  $extra
     */
    public function initiate(
        AppUser $user,
        ?Wallet $wallet,
        string $serviceId,
        int $amountKobo,
        string $phone,
        ?string $billersCode = null,
        ?string $variationCode = null,
        string $paymentMethod = 'payfixy',
        array $extra = [],
    ): AlternativePayment {
        $idempotencyKey = $extra['idempotency_key'] ?? Str::uuid()->toString();

        // Replay the first attempt verbatim — the client may retry after a
        // dropped connection and must never be charged twice.
        $existing = AlternativePayment::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $paymentReference = 'FPAP' . now()->format('YmdHis') . strtoupper(Str::random(6));
        $rail = $this->railService->getActiveRail('VTPASS', $user->tenant_id);
        $feeKobo = $rail ? $this->railService->calculateFee($rail, $amountKobo) : 0;

        $payment = AlternativePayment::create([
            'user_id'           => $user->id,
            'tenant_id'         => $user->tenant_id,
            'wallet_id'         => $wallet?->id,
            'payment_reference' => $paymentReference,
            // Stable fallback so verify() always has a handle the client was
            // given, even when the gateway echoes no reference of its own.
            'gateway_reference' => $paymentReference,
            'idempotency_key'   => $idempotencyKey,
            'payment_method'    => $paymentMethod,
            'service_id'        => $serviceId,
            'variation_code'    => $variationCode,
            'amount_kobo'       => $amountKobo,
            'fee_kobo'          => $feeKobo,
            'phone'             => $phone,
            'billers_code'      => $billersCode,
            'payment_status'    => 'PROCESSING',
            'request_payload'   => array_filter([
                'subscription_type' => $extra['subscription_type'] ?? null,
            ], fn ($v) => $v !== null),
        ]);

        return $this->submit($payment, $extra);
    }

    /**
     * Route the payment through the gateway and record the outcome.
     *
     * @param  array<string, mixed>  $extra
     */
    public function submit(AlternativePayment $payment, array $extra = []): AlternativePayment
    {
        try {
            $body = $this->gatewayClient->payBillWithMethod(
                paymentMethod: $payment->payment_method,
                serviceId: $payment->service_id,
                amount: round($payment->amount_kobo / 100, 2),
                phone: (string) $payment->phone,
                billersCode: $payment->billersCode,
                variationCode: $payment->variation_code,
                extra: array_filter([
                    // Canonical business reference forwarded to the gateway →
                    // TMS, so /ingest and the async score jobs share one row.
                    'reference'         => $payment->payment_reference,
                    'subscription_type' => $extra['subscription_type']
                        ?? ($payment->request_payload['subscription_type'] ?? null),
                ], fn ($v) => $v !== null),
            );

            $providerCode = GatewayClient::providerCode($body);
            $isSuccess = GatewayClient::isSuccess($body);

            $payment->update([
                'gateway_reference' => $this->extractGatewayReference($body) ?? $payment->payment_reference,
                'payment_status'    => $isSuccess ? 'COMPLETED' : 'FAILED',
                'provider_code'     => $providerCode,
                'response_payload'  => $body,
                'token'             => $body['content']['transactions']['token'] ?? $body['token'] ?? null,
                'units'             => $body['content']['transactions']['units'] ?? $body['units'] ?? null,
                'completed_at'      => $isSuccess ? now() : null,
                'failed_at'         => $isSuccess ? null : now(),
            ]);
        } catch (\Throwable $e) {
            // No wallet hold exists for this rail, so an ambiguous transport
            // failure cannot double-spend: leave the row PROCESSING (the client
            // shows "pending") and let the gateway's own reference resolve it.
            if ($this->isAmbiguousOutcome($e)) {
                $payment->update([
                    'payment_status'   => 'PROCESSING',
                    'response_payload' => ['error' => $e->getMessage()],
                ]);

                return $payment->fresh();
            }

            $payment->update([
                'payment_status'   => 'FAILED',
                'provider_code'    => $this->errorCode($e),
                'response_payload' => ['error' => $e->getMessage()],
                'failed_at'        => now(),
            ]);
        }

        return $payment->fresh();
    }

    /** Best-effort gateway reference from the wrapper's response body. */
    private function extractGatewayReference(array $body): ?string
    {
        $candidates = [
            $body['data']['reference'] ?? null,
            $body['data']['orderRef'] ?? null,
            $body['data']['transactionReference'] ?? null,
            $body['reference'] ?? null,
            $body['orderRef'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && (string) $candidate !== '') {
                return (string) $candidate;
            }
        }

        return null;
    }

    /** Provider/error code for observability when the gateway rejects a request. */
    private function errorCode(\Throwable $e): ?string
    {
        if ($e instanceof GatewayRequestException) {
            $body = $e->body();
            if (is_array($body)) {
                $code = $body['code'] ?? $body['responseCode'] ?? null;
                if (is_scalar($code)) {
                    return (string) $code;
                }
            }

            return (string) $e->status();
        }

        return null;
    }

    /**
     * Decide whether an exception means "the gateway outcome is unknown".
     *
     * Mirrors VtpassService::isAmbiguousOutcome: a transport error before any
     * HTTP response, or a 5xx/empty body from the gateway, is ambiguous. A
     * definitive 4xx (the provider was never reached) is not.
     */
    private function isAmbiguousOutcome(\Throwable $e): bool
    {
        if ($e instanceof \Illuminate\Http\Client\ConnectionException) {
            return true;
        }

        if ($e instanceof GatewayRequestException) {
            return $e->isServerError() || $e->hasEmptyBody();
        }

        if ($e instanceof \Illuminate\Database\QueryException
            || $e instanceof \Illuminate\Database\Eloquent\MassAssignmentException
            || $e instanceof \JsonException) {
            return false;
        }

        $message = strtolower($e->getMessage() ?? '');

        return str_contains($message, 'operation timed out')
            || str_contains($message, 'timed out')
            || str_contains($message, 'could not resolve host')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'empty reply')
            || str_contains($message, 'no response from');
    }
}
