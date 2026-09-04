<?php

namespace App\Services\Gateway;

use App\Models\Wallet;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the Payfixy Gateway — replaces all direct processor calls.
 *
 * Authentication: API key (secret key in Authorization header, public key in Client-Public).
 * Idempotency: X-Idempotency-Key sent on all mutating requests.
 * Wallet routing: wallet_provider resolved from the wallets table and included in every payload.
 */
class GatewayClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly string $businessId,
    ) {}

    // ─────────────────────────────────────────────────────────────
    // Wallet Operations
    // ─────────────────────────────────────────────────────────────

    /** Open a new wallet with the specified provider. */
    public function openWallet(string $provider, array $payload, string $reference): array
    {
        $payload['wallet_provider'] = $provider;
        $payload['reference']       = $reference;
        return $this->post('/api/v1/mobile/wallet/open', $payload);
    }

    /** Enquire wallet balance and details. */
    public function walletEnquiry(string $accountNo, string $provider = '9psb'): array
    {
        return $this->post('/api/v1/mobile/wallet/enquiry', [
            'wallet_provider' => $provider,
            'accountNo'       => $accountNo,
        ]);
    }

    /** Debit a wallet. */
    public function debitWallet(Wallet $wallet, float $amount, string $transactionId): array
    {
        return $this->post('/api/v1/mobile/wallet/debit', [
            'wallet_provider' => $wallet->wallet_provider,
            'accountNo'       => $wallet->account_no,
            'totalAmount'     => $amount,
            'transactionId'   => $transactionId,
        ]);
    }

    /** Credit a wallet. */
    public function creditWallet(Wallet $wallet, float $amount, string $transactionId): array
    {
        return $this->post('/api/v1/mobile/wallet/credit', [
            'wallet_provider' => $wallet->wallet_provider,
            'accountNo'       => $wallet->account_no,
            'totalAmount'     => $amount,
            'transactionId'   => $transactionId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Bill Payment Operations
    // ─────────────────────────────────────────────────────────────

    /**
     * Pay a bill using the user's wallet.
     *
     * Automatically resolves wallet_provider from the DB and includes it
     * in the payload so the Gateway routes to the correct processor.
     */
    public function payBillWithWallet(
        Wallet $wallet,
        string $serviceId,
        float  $amount,
        string $phone,
        ?string $billersCode = null,
        ?string $variationCode = null,
        array  $extra = [],
        ?string $reference = null,
    ): array {
        $this->authorizeWallet($wallet);

        $payload = array_filter([
            'payment_method'  => 'wallet',
            'wallet_provider' => $wallet->wallet_provider,      // ← from DB
            'wallet_account'  => $wallet->account_no,
            'service_id'      => $serviceId,
            'amount'          => $amount,
            'phone'           => $phone,
            'billersCode'     => $billersCode,
            'variation_code'  => $variationCode,
            // Canonical business reference forwarded to the gateway → TMS, so
            // /ingest and the async score job share ONE antifraud row.
            'reference'       => $reference,
            ...$extra,
        ], fn($v) => $v !== null);

        return $this->post('/api/v1/mobile/bills/pay', $payload);
    }

    /**
     * Pay a bill using a non-wallet method (card, bank transfer, USSD).
     * No wallet_provider needed — the Gateway uses channel routing.
     */
    public function payBillWithMethod(
        string $paymentMethod,
        string $serviceId,
        float  $amount,
        string $phone,
        ?string $billersCode = null,
        ?string $variationCode = null,
        array  $extra = []
    ): array {
        $payload = array_filter([
            'payment_method'  => $paymentMethod,
            'service_id'      => $serviceId,
            'amount'          => $amount,
            'phone'           => $phone,
            'billersCode'     => $billersCode,
            'variation_code'  => $variationCode,
            ...$extra,
        ], fn($v) => $v !== null);

        return $this->post('/api/v1/mobile/bills/pay', $payload);
    }

    /** Verify a bill meter/smartcard number. */
    public function verifyMeter(string $serviceId, string $billersCode, ?string $type = null): array
    {
        return $this->post('/api/v1/mobile/bills/verify', array_filter([
            'serviceID'   => $serviceId,
            'billersCode' => $billersCode,
            'type'        => $type,
        ]));
    }

    // ─────────────────────────────────────────────────────────────
    // Bill Catalog
    // ─────────────────────────────────────────────────────────────

    public function getBillCategories(): array
    {
        return $this->get('/api/v1/mobile/bills/categories');
    }

    public function getBillServices(?string $category = null): array
    {
        $query = $category ? '?category=' . urlencode($category) : '';
        return $this->get('/api/v1/mobile/bills/services' . $query);
    }

    public function getBillVariations(string $serviceId): array
    {
        return $this->get('/api/v1/mobile/bills/variations?service_id=' . urlencode($serviceId));
    }

    // ─────────────────────────────────────────────────────────────
    // Bank Transfer
    // ─────────────────────────────────────────────────────────────

    /** Bank transfer via wallet balance. */
    public function transferFromWallet(
        Wallet $wallet,
        string $recipientAccount,
        string $recipientBankCode,
        float  $amount,
        string $narration = '',
        ?string $reference = null,
    ): array {
        $this->authorizeWallet($wallet);

        return $this->post('/api/v1/mobile/transfer/bank', [
            'payment_method'   => 'wallet',
            'wallet_provider'  => $wallet->wallet_provider,
            'wallet_account'   => $wallet->account_no,
            'recipient_account'=> $recipientAccount,
            'bank_code'        => $recipientBankCode,
            'amount'           => $amount,
            'narration'        => $narration,
            // Canonical business reference forwarded to the gateway → TMS.
            'reference'        => $reference,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // HTTP Helpers
    // ─────────────────────────────────────────────────────────────

    private function post(string $path, array $payload): array
    {
        // The gateway uses canonical processor names ('9psb') while fixpay stores
        // 'ninepsb' in its wallets table — normalize so the contract holds.
        if (isset($payload['wallet_provider'])) {
            $payload['wallet_provider'] = $this->canonicalProvider((string) $payload['wallet_provider']);
        }

        Log::debug('GatewayClient: POST ' . $path, ['payload' => $this->redact($payload)]);

        $response = Http::timeout(60)
            ->withoutVerifying()
            ->withHeaders($this->authHeaders())
            ->post($this->baseUrl . $path, $payload);

        return $this->handleResponse($response, $path);
    }

    private function get(string $path): array
    {
        Log::debug('GatewayClient: GET ' . $path);

        $response = Http::timeout(30)
            ->withoutVerifying()
            ->withHeaders($this->authHeaders())
            ->get($this->baseUrl . $path);

        return $this->handleResponse($response, $path);
    }

    private function authHeaders(): array
    {
        $userId = auth()->id();
        $deviceId = request()->header('X-Device-Id', '');

        return [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Client-Public' => $this->apiKey,
            'X-Business-Id' => $this->businessId,
            // Scoped identity headers — the gateway buckets velocity/blocking by
            // (source, business_id, user_id). source is pinned to "mobile"; user_id
            // comes from Laravel auth (never client input); device_id is the
            // client-supplied cross-channel correlation key.
            'X-Source'      => 'mobile',
            'X-User-Id'     => $userId === null ? '' : (string) $userId,
            'X-Device-Id'   => is_string($deviceId) ? $deviceId : '',
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    /** Map fixpay's processor names to the gateway's canonical names. */
    private function canonicalProvider(string $provider): string
    {
        return match (strtolower($provider)) {
            'ninepsb' => '9psb',
            default   => $provider,
        };
    }

    private function handleResponse($response, string $path): array
    {
        $body = $response->json() ?? [];

        if ($response->failed()) {
            Log::error('GatewayClient: request failed', [
                'path'        => $path,
                'status_code' => $response->status(),
                'body'        => $body,
            ]);

            throw new GatewayRequestException(
                path: $path,
                status: $response->status(),
                body: $body !== [] ? $body : $response->body(),
                message: sprintf('Gateway request failed [%s]: HTTP %d — %s',
                    $path, $response->status(), $body['message'] ?? $response->body()),
            );
        }

        return $body;
    }

    /** Ensure the wallet belongs to the currently authenticated user. */
    private function authorizeWallet(Wallet $wallet): void
    {
        $userId = auth()->id();
        if (!$userId || $wallet->user_id !== $userId) {
            throw new \RuntimeException('Wallet does not belong to the authenticated user.');
        }
    }

    private function redact(array $data): array
    {
        $redacted = $data;
        $sensitiveKeys = ['bvn', 'password', 'clientSecret', 'nin', 'secret_key'];
        foreach ($sensitiveKeys as $key) {
            if (isset($redacted[$key])) {
                $redacted[$key] = '[REDACTED]';
            }
        }
        return $redacted;
    }

    /**
     * Check if a response indicates success.
     *
     * <p><strong>Payment safety:</strong> the gateway wrapper's {@code status}
     * reflects "the gateway processed the request", NOT whether the provider
     * delivered. When the body embeds the provider result in {@code data}, the
     * inner provider status/code is what decides the outcome — a provider
     * failure (e.g. 9PSB VAS {@code responseCode:300}) must never be treated as
     * success, otherwise the wallet is debited for a failed purchase.</p>
     */
    public static function isSuccess(array $response): bool
    {
        // 1. The wrapper must indicate the gateway processed the request — OR the
        // body is a raw provider result with an explicit success code (e.g. VTpass
        // {code:"000"} returned directly by the switch route).
        $status = $response['status'] ?? $response['code'] ?? null;
        $topCode = (string) ($response['code'] ?? $response['responseCode'] ?? '');
        $topCodeIsSuccess = in_array($topCode, ['00', '000', '200', '0', '020', '099'], true);
        if (!($status === true || $status === 'success' || $status === 'SUCCESS')
            && ($response['responseCode'] ?? null) !== '00'
            && !$topCodeIsSuccess) {
            return false;
        }

        // 2. Inspect the embedded provider result when present.
        $data = $response['data'] ?? null;
        if (is_array($data) && ($data['status'] ?? null) !== null) {
            $innerStatus = strtolower((string) $data['status']);
            if (in_array($innerStatus, ['failed', 'fail', 'error', 'unsuccessful'], true)) {
                return false;
            }
            $innerCode = (string) ($data['responseCode'] ?? $data['code'] ?? '');
            $innerSuccess = in_array($innerCode, ['00', '000', '200', '0', '020', '099'], true);
            $innerExplicitSuccess = in_array($innerStatus,
                ['success', 'successful', 'delivered', 'completed', 'pending'], true);
            if (!$innerCode && !$innerExplicitSuccess) {
                return false;   // provider body with an unknown status
            }
            if ($innerCode && !$innerSuccess && !$innerExplicitSuccess) {
                return false;   // provider returned a non-success code
            }
        } elseif (is_array($data) && isset($data['responseCode'])) {
            // Provider body without a status field — trust the response code.
            $innerCode = (string) $data['responseCode'];
            if (!in_array($innerCode, ['00', '000', '200', '0', '020', '099'], true)) {
                return false;
            }
        }

        // 3. Raw provider body at the top level (e.g. VTpass {code:"000"}).
        $code = (string) ($response['code'] ?? $response['responseCode'] ?? '');
        if ($code !== '') {
            return in_array($code, ['00', '000', '200', '0', '020', '099'], true);
        }

        return true;
    }

    /** Extract the provider's response code for observability (null when absent). */
    public static function providerCode(array $response): ?string
    {
        $data = $response['data'] ?? null;
        if (is_array($data)) {
            $inner = $data['responseCode'] ?? $data['code'] ?? null;
            if ($inner !== null) {
                return (string) $inner;
            }
        }
        $top = $response['responseCode'] ?? $response['code'] ?? null;
        return $top !== null ? (string) $top : null;
    }
}
