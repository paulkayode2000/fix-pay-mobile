<?php

namespace App\Services\Payment;

use App\Models\AppUser;
use App\Models\PaymentJournalEntry;
use App\Models\VtpassPayment;
use App\Models\Wallet;
use App\Services\Gateway\GatewayClient;
use App\Services\Gateway\GatewayRequestException;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VtpassService
{
    public function __construct(
        private readonly GatewayClient $gatewayClient,
        private readonly WalletService $walletService,
        private readonly PaymentRailService $railService,
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly string $publicKey,
        private readonly string $baseUrl,
    ) {}

    /**
     * Initiate a VTPass bill payment. Returns the VtpassPayment record.
     */
    public function initiate(
        AppUser $user,
        ?Wallet $wallet,
        string $serviceId,
        int $amountKobo,
        string $phone,
        ?string $billersCode = null,
        ?string $variationCode = null,
        array $extra = [],
        string $paymentMethod = 'wallet',
    ): VtpassPayment {
        $idempotencyKey = $extra['idempotency_key'] ?? Str::uuid()->toString();

        // Check existing by idempotency key
        $existing = VtpassPayment::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $paymentReference = 'FP' . now()->format('YmdHis') . strtoupper(Str::random(6));
        $rail = $this->railService->getActiveRail('VTPASS', $user->tenant_id);
        $feeKobo = $rail ? $this->railService->calculateFee($rail, $amountKobo) : 0;

        return DB::transaction(function () use (
            $user, $wallet, $serviceId, $amountKobo, $phone,
            $billersCode, $variationCode, $paymentReference, $idempotencyKey,
            $feeKobo, $rail, $paymentMethod
        ) {
            $totalDebit = $amountKobo + $feeKobo;

            if ($paymentMethod === 'wallet') {
                if (!$wallet) {
                    throw new \Exception('Wallet is required for wallet payment method');
                }
                // Hold funds (raises exception if insufficient)
                $this->walletService->hold($wallet, $totalDebit);
            }

            $payment = VtpassPayment::create([
                'user_id'          => $user->id,
                'wallet_id'        => $wallet?->id,
                'tenant_id'        => $user->tenant_id,
                'payment_reference'=> $paymentReference,
                'idempotency_key'  => $idempotencyKey,
                'service_id'       => $serviceId,
                'variation_code'   => $variationCode,
                'amount_kobo'      => $amountKobo,
                'fee_kobo'         => $feeKobo,
                'phone'            => $phone,
                'billersCode'      => $billersCode,
                'payment_status'   => 'PENDING',
                'processor_id'     => $rail?->processor_id,
                'processor_fee_kobo' => $feeKobo,
                // Store extra metadata (subscription_type etc.) for use at submit time
                'request_payload'  => array_filter([
                    'subscription_type' => $extra['subscription_type'] ?? null,
                ], fn ($v) => $v !== null),
            ]);

            $this->log($payment, 'HOLD', 'SUCCESS', ['total_debit_kobo' => $totalDebit]);

            return $payment;
        });
    }

    /**
     * Submit payment to VTPass API.
     */
    public function submit(VtpassPayment $payment): VtpassPayment
    {
        $payment->update(['payment_status' => 'PROCESSING']);
        $this->log($payment, 'SUBMIT', 'PROCESSING');

        try {
            $requestId = now()->format('YmdHis') . $payment->payment_reference;
            $payload = [
                'request_id'        => $requestId,
                'serviceID'         => $payment->service_id,
                'amount'            => (int) round($payment->amount_kobo / 100),
                'phone'             => $payment->phone,
                'billersCode'       => $payment->billersCode,
                'variation_code'    => $payment->variation_code,
                // Include subscription_type for TV services if stored at initiation
                'subscription_type' => $payment->request_payload['subscription_type'] ?? null,
            ];

            if (config('services.gateway.enabled', false) && $payment->wallet_id) {
                // The gateway route (tx BillRouteController) uses the payment
                // reference as the provider request_id — record it so a later
                // timeout requery can resolve the outcome.
                $payment->update(['provider_request_id' => $payment->payment_reference]);
                $result = $this->gatewayClient->payBillWithWallet(
                    Wallet::find($payment->wallet_id),
                    $payment->service_id,
                    round($payment->amount_kobo / 100, 2),
                    $payment->phone,
                    $payment->billersCode,
                    $payment->variation_code,
                    reference: $payment->payment_reference,
                );
                $body = $result;
                $providerCode = GatewayClient::providerCode($body);
                $isSuccess = GatewayClient::isSuccess($body);
            } else {
                // Direct rail — record the request_id sent to VTpass so a later
                // timeout requery can resolve the outcome.
                $payment->update(['provider_request_id' => $requestId]);
                $response = \Illuminate\Support\Facades\Http::timeout(60)->withoutVerifying()
                    ->withHeaders([
                        'api-key' => $this->apiKey,
                        'secret-key' => $this->secretKey,
                        'public-key' => $this->publicKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post("{$this->baseUrl}/pay", array_filter($payload, fn ($v) => $v !== null));

                $body = $response->json();
                $providerCode = $body['code'] ?? null;
                $isSuccess = in_array($providerCode, ['000', '099']);
            }

            $payment->update([
                'payment_status' => $isSuccess ? 'COMPLETED' : 'FAILED',
                'provider_code' => $providerCode,
                'request_payload' => $payload,
                'response_payload' => $body,
                'token' => $body['content']['transactions']['token'] ?? null,
                'units' => $body['content']['transactions']['units'] ?? null,
                'completed_at' => $isSuccess ? now() : null,
                'failed_at' => ! $isSuccess ? now() : null,
            ]);

            $this->log($payment, 'RESPONSE', $isSuccess ? 'COMPLETED' : 'FAILED', $body);

            if ($isSuccess) {
                if ($payment->wallet_id) {
                    $wallet = Wallet::find($payment->wallet_id);
                    $totalDebit = $payment->amount_kobo + $payment->fee_kobo;
                    $this->walletService->commitHold($wallet, $totalDebit, $payment->payment_reference, "Bill payment: {$payment->service_id}");
                    $this->log($payment, 'COMMIT', 'SUCCESS', ['total_debit_kobo' => $totalDebit]);
                }
            } else {
                $this->releaseHoldForPayment($payment);
            }

        } catch (\Throwable $e) {
            // Ambiguous failures (timeout / connection dropped / gateway 5xx with
            // no answer) do NOT mean the provider failed — the money may have
            // moved. Keep the wallet hold and let the requery job resolve the
            // real outcome. Releasing here risks double-spending when the
            // provider actually delivered. Only definitive responses release
            // the hold.
            if ($this->isAmbiguousOutcome($e)) {
                $payment->update([
                    'payment_status' => 'PROCESSING',
                    'last_requeried_at' => null,
                ]);
                $this->log($payment, 'SUBMIT_AMBIGUOUS', 'PROCESSING', ['error' => $e->getMessage()]);
                return $payment->fresh();
            }

            $payment->update([
                'payment_status' => 'FAILED',
                'failed_at' => now(),
            ]);
            $this->log($payment, 'EXCEPTION', 'FAILED', ['error' => $e->getMessage()]);
            $this->releaseHoldForPayment($payment);
            throw $e;
        }

        return $payment->fresh();
    }

    /**
     * Decide whether an exception thrown during submit means "the provider
     * outcome is unknown — keep the hold". Everything else is a definitive
     * failure and releases the hold.
     *
     * Deliberately structured (typed exceptions first) and transport-specific.
     * Generic substring tests are dangerous: Laravel DB error text contains
     * "(Connection: pgsql, ...)", so a bare "connection" match used to mislabel
     * schema/DB failures as ambiguous and permanently lock user funds.
     */
    private function isAmbiguousOutcome(\Throwable $e): bool
    {
        // A transport error before any HTTP response (DNS, connect, read/empty
        // reply, timeout) is genuinely ambiguous.
        if ($e instanceof \Illuminate\Http\Client\ConnectionException) {
            return true;
        }

        // The gateway answered. A definitive client error (4xx) means the
        // provider was never reached → fail + release. A server error (5xx) or
        // an empty/unparseable body means the provider leg errored and the
        // outcome is unknown → keep the hold and requery.
        if ($e instanceof GatewayRequestException) {
            return $e->isServerError() || $e->hasEmptyBody();
        }

        // A DB / programming / serialization error is NOT a "money may have
        // moved" signal — it is a local defect and must fail loudly, never trap
        // the funds in a hold.
        if ($e instanceof \Illuminate\Database\QueryException
            || $e instanceof \Illuminate\Database\Eloquent\MassAssignmentException
            || $e instanceof \JsonException) {
            return false;
        }

        // Last resort: transport-only keywords (never the bare word
        // "connection", which also appears inside SQLSTATE error text).
        $message = strtolower($e->getMessage() ?? '');

        return str_contains($message, 'operation timed out')
            || str_contains($message, 'timed out')
            || str_contains($message, 'could not resolve host')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'empty reply')
            || str_contains($message, 'no response from')
            || str_contains($message, 'ssl: no alternative certificate subject');
    }

    /**
     * Requery the provider for a payment whose outcome is unknown (timeout).
     *
     * <p>Money-safe: only a definitive provider response decides the result.
     * Returns a classified outcome:
     * <ul>
     *   <li><b>delivered</b> — provider confirmed delivery → commit the hold</li>
     *   <li><b>failed</b> — provider confirmed failure → release the hold</li>
     *   <li><b>pending</b> — provider still processing → keep the hold</li>
     *   <li><b>not_found</b> — the request_id isn't on VTpass (another provider)</li>
     *   <li><b>unknown</b> — provider unreachable → keep the hold, requery later</li>
     * </ul>
     */
    public function requery(VtpassPayment $payment): array
    {
        $requestId = $payment->provider_request_id ?? $payment->payment_reference;
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)->withoutVerifying()
                ->withHeaders([
                    'api-key' => $this->apiKey,
                    'secret-key' => $this->secretKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/requery", ['request_id' => $requestId]);

            $body = $response->json() ?? [];
            $code = (string) ($body['code'] ?? '');
            $status = $body['content']['transactions']['status'] ?? null;

            if (in_array($status, ['delivered', 'failed', 'reversed'], true)) {
                return ['outcome' => $status === 'delivered' ? 'delivered' : 'failed', 'body' => $body];
            }
            if ($code === '000' && $status === null) {
                return ['outcome' => 'delivered', 'body' => $body];
            }
            if ($code === '099' || in_array($status, ['pending', 'initiated'], true)) {
                return ['outcome' => 'pending', 'body' => $body];
            }
            // 014 / request not found → this request_id was never on VTpass.
            return ['outcome' => 'not_found', 'body' => $body];
        } catch (\Throwable $e) {
            Log::warning("VTPass requery failed for {$payment->id}: {$e->getMessage()}");
            return ['outcome' => 'unknown', 'body' => ['error' => $e->getMessage()]];
        }
    }

    private function releaseHoldForPayment(VtpassPayment $payment): void
    {
        $wallet = Wallet::find($payment->wallet_id);
        if ($wallet) {
            $totalRefund = $payment->amount_kobo + $payment->fee_kobo;
            $this->walletService->releaseHold($wallet, $totalRefund);
            $this->log($payment, 'RELEASE_HOLD', 'SUCCESS', ['refunded_kobo' => $totalRefund]);
        }
    }

    private function log(VtpassPayment $payment, string $step, string $status, array $metadata = []): void
    {
        PaymentJournalEntry::create([
            'payment_id' => $payment->id,
            'step' => $step,
            'status' => $status,
            'metadata' => $metadata,
            'actor' => 'system',
        ]);
    }
}
