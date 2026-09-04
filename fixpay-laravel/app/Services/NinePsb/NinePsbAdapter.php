<?php

namespace App\Services\NinePsb;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP adapter for 9PSB Wallet-as-a-Service (WAAS) API v3.
 * Wraps all 18 endpoints with auth injection, error handling, and logging.
 */
class NinePsbAdapter
{
    public function __construct(
        private readonly NinePsbAuthService $auth,
        private readonly string $baseUrl,
    ) {}

    // ─────────────────────────────────────────────────────────────────
    // 1. AUTHENTICATION (handled by NinePsbAuthService)
    // ─────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────
    // 2. WALLET OPENING
    // ─────────────────────────────────────────────────────────────────

    /**
     * Create a new customer wallet account.
     * POST /api/v1/open_wallet
     */
    public function openWallet(array $payload): array
    {
        $payload['transactionTrackingRef'] ??= 'FP' . now()->format('YmdHis') . strtoupper(bin2hex(random_bytes(4)));

        return $this->post('/api/v1/open_wallet', $payload);
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. WALLET ENQUIRY
    // ─────────────────────────────────────────────────────────────────

    /**
     * Fetch details of a customer's wallet including balance.
     * POST /api/v1/wallet_enquiry
     */
    public function walletEnquiry(string $accountNo): array
    {
        return $this->post('/api/v1/wallet_enquiry', [
            'accountNo' => $accountNo,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. SINGLE WALLET DEBIT
    // ─────────────────────────────────────────────────────────────────

    /**
     * Debit a wallet account (wallet → client float).
     * POST /api/v1/debit/transfer
     */
    public function debitWallet(string $accountNo, float $amount, string $transactionId, string $narration = '', array $merchant = []): array
    {
        $merchantData = array_merge([
            'isFee' => false,
            'merchantFeeAccount' => '',
            'merchantFeeAmount' => '',
        ], $merchant);

        return $this->post('/api/v1/debit/transfer', [
            'accountNo' => $accountNo,
            'totalAmount' => $amount,
            'transactionId' => $transactionId,
            'narration' => $narration ?: "DEBIT/{$accountNo}/{$transactionId}",
            'merchant' => $merchantData,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. SINGLE WALLET CREDIT
    // ─────────────────────────────────────────────────────────────────

    /**
     * Credit a wallet account (client float → wallet).
     * POST /api/v1/credit/transfer
     */
    public function creditWallet(string $accountNo, float $amount, string $transactionId, string $narration = '', array $merchant = []): array
    {
        $merchantData = array_merge([
            'isFee' => false,
            'merchantFeeAccount' => '',
            'merchantFeeAmount' => '',
        ], $merchant);

        return $this->post('/api/v1/credit/transfer', [
            'accountNo' => $accountNo,
            'totalAmount' => $amount,
            'transactionId' => $transactionId,
            'narration' => $narration ?: "CREDIT/{$accountNo}/{$transactionId}",
            'merchant' => $merchantData,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 6. WALLET STATUS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Fetch status of a customer wallet.
     * POST /api/v1/wallet_status
     */
    public function walletStatus(string $accountNo): array
    {
        return $this->post('/api/v1/wallet_status', [
            'accountNo' => $accountNo,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 7. CHANGE WALLET STATUS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Update status of a customer wallet (ACTIVE or SUSPENDED).
     * POST /api/v1/change_wallet_status
     */
    public function changeWalletStatus(string $accountNumber, string $accountStatus): array
    {
        return $this->post('/api/v1/change_wallet_status', [
            'accountNumber' => $accountNumber,
            'accountStatus' => $accountStatus,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 8. WALLET TRANSACTION HISTORY
    // ─────────────────────────────────────────────────────────────────

    /**
     * Fetch a customer's transaction history.
     * POST /api/v1/wallet_transactions
     */
    public function walletTransactions(string $accountNumber, string $fromDate, string $toDate, string $numberOfItems = '100'): array
    {
        return $this->post('/api/v1/wallet_transactions', [
            'accountNumber' => $accountNumber,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'numberOfItems' => $numberOfItems,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 9. TRANSACTION STATUS QUERY (TSQ)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Verify the status of a transaction. Used for DEBIT, CREDIT, and OTHER_BANKS.
     * POST /api/v1/wallet_requery
     */
    public function walletRequery(string $transactionId, ?float $amount = null, ?string $transactionType = null, ?string $transactionDate = null, ?string $accountNo = null): array
    {
        return $this->post('/api/v1/wallet_requery', array_filter([
            'transactionId' => $transactionId,
            'amount' => $amount,
            'transactionType' => $transactionType,
            'transactionDate' => $transactionDate,
            'accountNo' => $accountNo,
        ], fn($v) => $v !== null));
    }

    // ─────────────────────────────────────────────────────────────────
    // 10. WALLET TO OTHER BANKS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Transfer from customer wallet to other bank.
     * POST /api/v1/wallet_other_banks
     */
    public function transferToOtherBank(
        string $senderAccountNumber,
        string $senderName,
        string $destinationAccountNumber,
        string $destinationAccountName,
        string $bankCode,
        string $amount,
        string $reference,
        string $narration = '',
        array $merchant = [],
    ): array {
        $merchantData = array_merge([
            'isFee' => false,
            'merchantFeeAccount' => '',
            'merchantFeeAmount' => '',
        ], $merchant);

        return $this->post('/api/v1/wallet_other_banks', [
            'customer' => [
                'account' => [
                    'bank' => $bankCode,
                    'name' => $destinationAccountName,
                    'number' => $destinationAccountNumber,
                    'senderaccountnumber' => $senderAccountNumber,
                    'sendername' => $senderName,
                ],
            ],
            'narration' => $narration ?: "TRANSFER/{$senderAccountNumber}/{$reference}",
            'order' => [
                'amount' => $amount,
                'country' => 'NGA',
                'currency' => 'NGN',
                'description' => $narration ?: 'Transfer',
            ],
            'transaction' => [
                'reference' => $reference,
            ],
            'merchant' => $merchantData,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 11. OTHER BANK ACCOUNT ENQUIRY
    // ─────────────────────────────────────────────────────────────────

    /**
     * Verify account details of another bank's account.
     * POST /api/v1/other_banks_enquiry
     */
    public function otherBankEnquiry(string $bankCode, string $accountNumber, ?string $senderAccountNumber = null): array
    {
        $account = [
            'bank' => $bankCode,
            'number' => $accountNumber,
        ];

        if ($senderAccountNumber) {
            $account['senderaccountnumber'] = $senderAccountNumber;
        }

        return $this->post('/api/v1/other_banks_enquiry', [
            'customer' => [
                'account' => $account,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 12. GET BANKS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Fetch list of all banks.
     * GET /api/v1/get_banks
     */
    public function getBanks(): array
    {
        return $this->get('/api/v1/get_banks');
    }

    // ─────────────────────────────────────────────────────────────────
    // 13. GET WALLET BY BVN
    // ─────────────────────────────────────────────────────────────────

    /**
     * Fetch wallet information using BVN.
     * POST /api/v1/get_wallet
     */
    public function getWalletByBvn(string $bvn): array
    {
        return $this->post('/api/v1/get_wallet', [
            'bvn' => $bvn,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 14. WALLET UPGRADE (Base64 JSON)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Upgrade wallet account tier (JSON/base64 payload).
     * POST /api/v1/wallet_upgrade
     */
    public function walletUpgrade(array $payload): array
    {
        return $this->post('/api/v1/wallet_upgrade', $payload);
    }

    // ─────────────────────────────────────────────────────────────────
    // 15. WALLET UPGRADE (Multipart file upload)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Upgrade wallet account tier using multipart/form-data with file uploads.
     * POST /api/v1/wallet_upgrade_file_upload
     */
    public function walletUpgradeFileUpload(array $multipartPayload): array
    {
        return $this->postMultipart('/api/v1/wallet_upgrade_file_upload', $multipartPayload);
    }

    // ─────────────────────────────────────────────────────────────────
    // 16. UPGRADE STATUS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Check the status of a wallet upgrade.
     * POST /api/v1/upgrade_status
     */
    public function upgradeStatus(string $accountNumber): array
    {
        return $this->post('/api/v1/upgrade_status', [
            'accountNumber' => $accountNumber,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 17. NOTIFICATION REQUERY
    // ─────────────────────────────────────────────────────────────────

    /**
     * Confirm an inflow credit/transfer webhook notification.
     * POST /api/v1/notification_requery
     */
    public function notificationRequery(string $sessionId, string $accountNumber): array
    {
        return $this->post('/api/v1/notification_requery', [
            'sessionID' => $sessionId,
            'accountNumber' => $accountNumber,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 18. OPEN CORPORATE ACCOUNT (Base64)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Open a corporate account using Base64 images.
     * POST /api/v1/open_corporate_account
     */
    public function openCorporateAccount(array $payload): array
    {
        return $this->post('/api/v1/open_corporate_account', $payload);
    }

    // ─────────────────────────────────────────────────────────────────
    // 19. OPEN CORPORATE ACCOUNT (Multipart)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Open a corporate account using image files (multipart/form-data).
     * POST /api/v1/open_corporate_account_file_upload
     */
    public function openCorporateAccountFileUpload(array $multipartPayload): array
    {
        return $this->postMultipart('/api/v1/open_corporate_account_file_upload', $multipartPayload);
    }

    // ─────────────────────────────────────────────────────────────────
    // 20. GET CORPORATE ACCOUNT NUMBER
    // ─────────────────────────────────────────────────────────────────

    /**
     * Get generated corporate account number by TIN.
     * POST /api/v1/get_account_number
     */
    public function getCorporateAccountNumber(string $taxIdNo): array
    {
        return $this->post('/api/v1/get_account_number', [
            'taxIDNo' => $taxIdNo,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 21. GET CORPORATE ACCOUNT STATUS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Get corporate account status (Pending or Approved).
     * POST /api/v1/get_request_status
     */
    public function getCorporateAccountStatus(string $accountNumber): array
    {
        return $this->post('/api/v1/get_request_status', [
            'accountNumber' => $accountNumber,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // HTTP HELPERS
    // ─────────────────────────────────────────────────────────────────

    private function post(string $path, array $data): array
    {
        $token = $this->auth->getToken();

        Log::debug('9PSB: POST request', ['path' => $path, 'payload' => $this->redactSensitiveData($data)]);

        $response = Http::timeout(60)
            ->withoutVerifying()
            ->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post("{$this->baseUrl}{$path}", $data);

        return $this->handleResponse($response, $path);
    }

    private function get(string $path): array
    {
        $token = $this->auth->getToken();

        Log::debug('9PSB: GET request', ['path' => $path]);

        $response = Http::timeout(60)
            ->withoutVerifying()
            ->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])
            ->get("{$this->baseUrl}{$path}");

        return $this->handleResponse($response, $path);
    }

    private function postMultipart(string $path, array $data): array
    {
        $token = $this->auth->getToken();

        Log::debug('9PSB: Multipart POST request', ['path' => $path]);

        $request = Http::timeout(120)
            ->withoutVerifying()
            ->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ]);

        // Attach multipart fields - files should be provided as paths or UploadedFile instances
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['file'])) {
                // File upload: ['file' => '/path/to/file', 'filename' => 'name.ext']
                $request = $request->attach(
                    $key,
                    file_get_contents($value['file']),
                    $value['filename'] ?? basename($value['file'])
                );
            } else {
                $request = $request->asMultipart();
                // Handled below
            }
        }

        $response = $request->post("{$this->baseUrl}{$path}", collect($data)
            ->filter(fn($v) => !is_array($v) || !isset($v['file']))
            ->toArray());

        return $this->handleResponse($response, $path);
    }

    private function handleResponse($response, string $path): array
    {
        $body = $response->json() ?? [];

        // Handle 401 - token expired
        if ($response->status() === 401) {
            Log::warning('9PSB: Token expired, invalidating cache', ['path' => $path]);
            $this->auth->invalidateToken();
            throw new \RuntimeException('9PSB: Authentication token expired. Retry the request.');
        }

        $status = $body['status'] ?? null;
        $message = $body['message'] ?? 'Unknown response';

        if ($status === 'FAILED' || ($response->failed() && !$body)) {
            Log::error('9PSB: Request failed', [
                'path' => $path,
                'status_code' => $response->status(),
                'body' => $body,
            ]);
            throw new \RuntimeException("9PSB request failed [{$path}]: {$message}");
        }

        Log::debug('9PSB: Response', [
            'path' => $path,
            'status' => $status,
            'message' => $message,
        ]);

        return $body;
    }

    /**
     * Check if a response indicates success.
     */
    public static function isSuccess(array $response): bool
    {
        $status = $response['status'] ?? null;
        $responseCode = $response['responseCode'] ?? $response['data']['responseCode'] ?? null;

        return $status === 'SUCCESS' || $responseCode === '00';
    }

    /**
     * Extract the response code from a 9PSB response.
     */
    public static function responseCode(array $response): ?string
    {
        return $response['responseCode']
            ?? $response['data']['responseCode']
            ?? $response['code']
            ?? null;
    }

    private function redactSensitiveData(array $data): array
    {
        $redacted = $data;
        $sensitiveKeys = ['bvn', 'password', 'clientSecret', 'nationalIdentityNo', 'nin', 'customerSignature',
            'customerImage', 'userPhoto', 'idCardFront', 'idCardBack', 'cacCertificate', 'utilityBill'];

        foreach ($sensitiveKeys as $key) {
            if (isset($redacted[$key])) {
                $redacted[$key] = '[REDACTED]';
            }
        }

        return $redacted;
    }
}