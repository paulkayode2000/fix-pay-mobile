<?php

namespace App\Services\Transfer;

use App\Models\AppUser;
use App\Models\NinePsbTransaction;
use App\Models\Transfer;
use App\Models\Wallet;
use App\Services\NinePsb\NinePsbAdapter;
use App\Services\Security\TransactionGuardService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles bank transfers via 9PSB WAAS API.
 * Replaces the Paystack transfer flow for 9PSB wallets.
 * 
 * Flow: name enquiry → debit wallet → bank transfer → TSQ confirmation
 */
class NinePsbTransferService
{
    private const TSQ_MAX_RETRIES = 3;
    private const TSQ_RETRY_DELAY_SECONDS = 5;

    public function __construct(
        private readonly NinePsbAdapter $ninePsb,
        private readonly WalletService $walletService,
        private readonly TransactionGuardService $guard,
    ) {}

    /**
     * Initiate a bank transfer through 9PSB.
     */
    public function initiateBankTransfer(
        AppUser $user,
        Wallet $wallet,
        Request $request,
        int $amountKobo,
        string $accountNumber,
        string $bankCode,
        string $narration = 'Transfer',
        ?string $idempotencyKey = null,
    ): Transfer {
        $idempotencyKey ??= Str::uuid()->toString();

        // Idempotency check
        $duplicate = $this->guard->checkIdempotency($idempotencyKey);
        if ($duplicate) {
            throw new \RuntimeException('Duplicate transfer detected. Use existing reference.');
        }

        $existing = Transfer::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        // Security guardrails
        $this->guard->enforce($wallet, $request);

        $reference = 'FPT' . now()->format('YmdHis') . strtoupper(Str::random(4));
        $amountNaira = number_format($amountKobo / 100, 2, '.', '');

        Log::info('9PSB Transfer: Initiating', [
            'user_id' => $user->id,
            'account_number' => $wallet->ninepsb_account_number,
            'amount' => $amountNaira,
            'destination' => $accountNumber,
            'bank_code' => $bankCode,
        ]);

        return DB::transaction(function () use (
            $user, $wallet, $request, $amountKobo, $amountNaira,
            $accountNumber, $bankCode, $narration, $reference, $idempotencyKey
        ) {
            // Step 1: Name enquiry (verify destination account)
            $accountName = $this->doNameEnquiry($bankCode, $accountNumber, $wallet->ninepsb_account_number);
            $bankName = $this->resolveBankName($bankCode);

            // Step 2: Create transfer record
            $transfer = Transfer::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'tenant_id' => $user->tenant_id,
                'transfer_reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'transfer_type' => 'BANK',
                'amount_kobo' => $amountKobo,
                'fee_kobo' => 5250, // ₦52.50 standard fee
                'narration' => $narration,
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'bank_name' => $bankName,
                'account_name' => $accountName,
                'status' => 'INITIATED',
            ]);

            try {
                // Step 3: Execute bank transfer via 9PSB
                $response = $this->ninePsb->transferToOtherBank(
                    senderAccountNumber: $wallet->ninepsb_account_number,
                    senderName: $wallet->ninepsb_metadata['full_name'] ?? "{$user->first_name} {$user->last_name}",
                    destinationAccountNumber: $accountNumber,
                    destinationAccountName: $accountName,
                    bankCode: $bankCode,
                    amount: $amountNaira,
                    reference: $reference,
                    narration: $narration,
                );

                $responseCode = NinePsbAdapter::responseCode($response);

                // Record the 9PSB transaction
                NinePsbTransaction::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'tenant_id' => $user->tenant_id,
                    'transaction_id' => $reference,
                    'transaction_type' => 'OTHER_BANKS',
                    'account_number' => $wallet->ninepsb_account_number,
                    'amount' => $amountNaira,
                    'fee_amount' => 52.50,
                    'status' => $responseCode === '00' ? 'SUCCESS' : 'PENDING',
                    'response_code' => $responseCode,
                    'response_message' => $response['message'] ?? null,
                    'narration' => $narration,
                    'request_payload' => [
                        'destination_account' => $accountNumber,
                        'bank_code' => $bankCode,
                    ],
                    'response_payload' => $response,
                    'transaction_date' => now(),
                ]);

                $transfer->update([
                    'provider_response' => $response,
                ]);

                // Step 4: Handle response
                if ($responseCode === '00') {
                    // Success — commit
                    $this->walletService->debit(
                        $wallet,
                        $amountKobo + 5250,
                        $reference,
                        "Bank transfer to {$accountNumber}: {$narration}"
                    );

                    $transfer->update([
                        'status' => 'PROCESSING',
                        'completed_at' => now(),
                    ]);

                    Log::info('9PSB Transfer: Success', [
                        'reference' => $reference,
                        'response_code' => $responseCode,
                    ]);

                } elseif (in_array($responseCode, ['09', '96', '97', '98', '99'])) {
                    // Requires TSQ
                    $transfer->update(['status' => 'PROCESSING']);
                    
                    // Attempt TSQ polling
                    $finalStatus = $this->pollTsq($reference, $amountNaira, $wallet->ninepsb_account_number);
                    
                    if ($finalStatus === '00') {
                        $this->walletService->debit($wallet, $amountKobo + 5250, $reference, "Bank transfer to {$accountNumber}: {$narration}");
                        $transfer->update(['status' => 'COMPLETED', 'completed_at' => now()]);
                    } else {
                        // TSQ didn't resolve — leave as PROCESSING, will reconcile via webhook
                        Log::warning('9PSB Transfer: TSQ unresolved', [
                            'reference' => $reference,
                            'final_code' => $finalStatus,
                        ]);
                    }

                } elseif ($responseCode === '51') {
                    // Insufficient funds
                    $transfer->update(['status' => 'FAILED', 'failed_at' => now(), 'failure_reason' => 'Insufficient funds']);
                    throw new \RuntimeException('Insufficient funds in wallet.');

                } else {
                    // Other failure
                    $transfer->update([
                        'status' => 'FAILED',
                        'failed_at' => now(),
                        'failure_reason' => $response['message'] ?? "Response code: {$responseCode}",
                    ]);
                    throw new \RuntimeException($response['message'] ?? "Transfer failed with code {$responseCode}");
                }

            } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), 'Duplicate transfer')) {
                    Log::error('9PSB Transfer: Failed', [
                        'reference' => $reference,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($transfer->status !== 'PROCESSING' && $transfer->status !== 'COMPLETED') {
                    $transfer->update(['status' => 'FAILED', 'failed_at' => now(), 'failure_reason' => $e->getMessage()]);
                }
                throw $e;
            }

            // Record idempotency
            $this->guard->recordIdempotency($idempotencyKey, [
                'transfer_reference' => $reference,
                'status' => $transfer->status,
                'amount_kobo' => $amountKobo,
            ]);

            return $transfer->fresh();
        });
    }

    /**
     * Verify destination bank account via 9PSB name enquiry.
     */
    public function verifyAccount(string $bankCode, string $accountNumber, Wallet $wallet): array
    {
        $this->ninePsb->otherBankEnquiry($bankCode, $accountNumber, $wallet->ninepsb_account_number);

        // The adapter throws on failure, so if we reach here, enquiry succeeded
        // Fetch full enquiry response
        $response = $this->ninePsb->otherBankEnquiry($bankCode, $accountNumber, $wallet->ninepsb_account_number);
        $data = $response['data'] ?? [];

        return [
            'accountNumber' => $accountNumber,
            'bankCode' => $bankCode,
            'accountName' => $data['accountName'] ?? $data['name'] ?? 'Unknown',
            'status' => $response['status'] ?? 'SUCCESS',
        ];
    }

    /**
     * Get list of banks from 9PSB.
     */
    public function getBanks(): array
    {
        $response = $this->ninePsb->getBanks();
        $bankList = $response['data']['bankList'] ?? [];

        // Map to consistent format
        return array_map(fn($b) => [
            'bankName' => $b['bankName'] ?? $b['bank_name'] ?? 'Unknown',
            'bankCode' => $b['bankCode'] ?? $b['bank_code'] ?? $b['nibssBankCode'] ?? 'N/A',
            'nibssBankCode' => $b['nibssBankCode'] ?? $b['bankCode'] ?? $b['bank_code'] ?? 'N/A',
        ], $bankList);
    }

    // ─────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────

    private function doNameEnquiry(string $bankCode, string $accountNumber, string $senderAccount): string
    {
        $response = $this->ninePsb->otherBankEnquiry($bankCode, $accountNumber, $senderAccount);
        $data = $response['data'] ?? [];

        return $data['accountName'] ?? $data['name'] ?? 'Unknown';
    }

    private function resolveBankName(string $bankCode): string
    {
        // Static cache to avoid repeated API calls
        static $bankCache = null;

        if ($bankCache === null) {
            try {
                $response = $this->ninePsb->getBanks();
                $bankList = $response['data']['bankList'] ?? [];
                foreach ($bankList as $b) {
                    $code = $b['bankCode'] ?? $b['nibssBankCode'] ?? 'N/A';
                    $name = $b['bankName'] ?? $b['bank_name'] ?? 'Unknown';
                    $bankCache[$code] = $name;
                }
            } catch (\Throwable) {
                $bankCache = [];
            }
        }

        return $bankCache[$bankCode] ?? 'Unknown Bank';
    }

    /**
     * Poll TSQ endpoint for final transfer status.
     */
    private function pollTsq(string $transactionId, string $amount, string $accountNo): ?string
    {
        for ($i = 0; $i < self::TSQ_MAX_RETRIES; $i++) {
            sleep(self::TSQ_RETRY_DELAY_SECONDS);

            try {
                $response = $this->ninePsb->walletRequery(
                    $transactionId,
                    (float) $amount,
                    'OTHER_BANKS',
                    now()->format('Y-m-d'),
                    $accountNo,
                );

                $code = NinePsbAdapter::responseCode($response);

                if ($code === '00') {
                    return '00'; // Success
                }

                // If it's still in TSQ codes, continue polling
                if (!in_array($code, ['09', '96', '97', '98', '99'])) {
                    return $code; // Definitive failure
                }
            } catch (\Throwable $e) {
                Log::warning('9PSB TSQ: Poll attempt failed', [
                    'attempt' => $i + 1,
                    'transaction_id' => $transactionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null; // Still unresolved after retries
    }
}