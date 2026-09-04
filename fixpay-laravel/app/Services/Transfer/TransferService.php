<?php

namespace App\Services\Transfer;

use App\Models\AppUser;
use App\Models\Transfer;
use App\Models\Wallet;
use App\Services\Gateway\GatewayClient;
use App\Services\Payment\PaymentRailService;
use App\Services\Wallet\WalletService;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransferService
{
    public function __construct(
        private readonly Client $http,
        private readonly GatewayClient $gatewayClient,
        private readonly WalletService $walletService,
        private readonly PaymentRailService $railService,
        private readonly string $paystackSecretKey,
        private readonly string $paystackBaseUrl,
    ) {}

    public function initiateBank(
        AppUser $user,
        Wallet $wallet,
        int $amountKobo,
        string $accountNumber,
        string $bankCode,
        string $narration = 'Transfer',
        ?string $idempotencyKey = null,
    ): Transfer {
        $idempotencyKey ??= Str::uuid()->toString();
        $existing = Transfer::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $reference = 'FPT' . now()->format('YmdHis') . strtoupper(Str::random(6));
        $rail = $this->railService->getActiveRail('BANK_TRANSFER', $user->tenant_id);
        $feeKobo = $rail ? $this->railService->calculateFee($rail, $amountKobo) : 0;

        $transfer = DB::transaction(function () use (
            $user, $wallet, $amountKobo, $accountNumber, $bankCode, $narration,
            $reference, $idempotencyKey, $feeKobo
        ) {
            $totalDebit = $amountKobo + $feeKobo;
            $this->walletService->hold($wallet, $totalDebit);

            // Resolve account name via Paystack
            $accountName = $this->resolveAccountName($accountNumber, $bankCode);
            $bankName = $this->resolveBankName($bankCode);

            // Create Paystack recipient
            $recipientCode = $this->createPaystackRecipient($accountNumber, $bankCode, $accountName);

            $transfer = Transfer::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'tenant_id' => $user->tenant_id,
                'transfer_reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'transfer_type' => 'BANK',
                'amount_kobo' => $amountKobo,
                'fee_kobo' => $feeKobo,
                'narration' => $narration,
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'bank_name' => $bankName,
                'account_name' => $accountName,
                'paystack_recipient_code' => $recipientCode,
                'status' => 'INITIATED',
            ]);

            // Initiate Paystack transfer
            $this->submitPaystackTransfer($transfer);

            return $transfer->fresh();
        });

        // Asynchronous TMS checks (antifraud + AML) — tag the transfer when done.
        $this->dispatchRiskJobs($transfer);

        return $transfer;
    }

    public function initiateWallet(
        AppUser $user,
        Wallet $wallet,
        int $amountKobo,
        string $recipientPhone,
        string $narration = 'Wallet transfer',
        ?string $idempotencyKey = null,
    ): Transfer {
        $idempotencyKey ??= Str::uuid()->toString();
        $existing = Transfer::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $recipient = AppUser::where('phone', $recipientPhone)->first();
        if (! $recipient) {
            throw new \RuntimeException('Recipient not found.');
        }

        $recipientWallet = $recipient->wallet;
        if (! $recipientWallet || $recipientWallet->status !== 'ACTIVE') {
            throw new \RuntimeException('Recipient wallet is not active.');
        }

        $reference = 'FPW' . now()->format('YmdHis') . strtoupper(Str::random(6));

        $transfer = DB::transaction(function () use (
            $user, $wallet, $recipient, $recipientWallet, $amountKobo,
            $narration, $reference, $idempotencyKey
        ) {
            $this->walletService->debit($wallet, $amountKobo, $reference, "Wallet transfer to {$recipient->phone}");
            $this->walletService->credit($recipientWallet, $amountKobo, $reference, "Wallet transfer from {$user->phone}");

            return Transfer::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'tenant_id' => $user->tenant_id,
                'transfer_reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'transfer_type' => 'WALLET',
                'amount_kobo' => $amountKobo,
                'fee_kobo' => 0,
                'narration' => $narration,
                'recipient_user_id' => $recipient->id,
                'recipient_wallet_id' => $recipientWallet->id,
                'status' => 'COMPLETED',
                'completed_at' => now(),
            ]);
        });

        // Asynchronous TMS checks (antifraud + AML) — tag the transfer when done.
        $this->dispatchRiskJobs($transfer);

        return $transfer;
    }

    /**
     * Fire the two independent asynchronous TMS risk checks for a transaction.
     * Failures are logged and never block the payment path.
     */
    private function dispatchRiskJobs(\App\Models\Transfer $transfer): void
    {
        try {
            \App\Jobs\AntifraudScoreJob::dispatch($transfer, request()->header('X-Device-Id', ''));
            \App\Jobs\TransactionAmlScreenJob::dispatch($transfer);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to dispatch TMS risk jobs for transfer', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function resolveAccountName(string $accountNumber, string $bankCode): string
    {
        try {
            $response = $this->http->get("{$this->paystackBaseUrl}/bank/resolve", [
                'headers' => ['Authorization' => "Bearer {$this->paystackSecretKey}"],
                'query' => ['account_number' => $accountNumber, 'bank_code' => $bankCode],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            return $data['data']['account_name'] ?? 'Unknown';
        } catch (\Throwable) {
            return 'Unknown';
        }
    }

    private function resolveBankName(string $bankCode): string
    {
        // Common Nigerian bank codes
        $banks = [
            '058' => 'Guaranty Trust Bank',
            '011' => 'First Bank',
            '044' => 'Access Bank',
            '063' => 'Access Bank (Diamond)',
            '050' => 'Ecobank',
            '070' => 'Fidelity Bank',
            '214' => 'First City Monument Bank',
            '030' => 'Heritage Bank',
            '301' => 'Jaiz Bank',
            '082' => 'Keystone Bank',
            '526' => 'Parallex Bank',
            '076' => 'Polaris Bank',
            '101' => 'Providus Bank',
            '221' => 'Stanbic IBTC',
            '068' => 'Standard Chartered',
            '232' => 'Sterling Bank',
            '032' => 'Union Bank',
            '033' => 'United Bank for Africa',
            '215' => 'Unity Bank',
            '035' => 'Wema Bank',
            '057' => 'Zenith Bank',
        ];

        return $banks[$bankCode] ?? 'Unknown Bank';
    }

    private function createPaystackRecipient(string $accountNumber, string $bankCode, string $accountName): ?string
    {
        try {
            $response = $this->http->post("{$this->paystackBaseUrl}/transferrecipient", [
                'headers' => [
                    'Authorization' => "Bearer {$this->paystackSecretKey}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'type' => 'nuban',
                    'name' => $accountName,
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                    'currency' => 'NGN',
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            return $data['data']['recipient_code'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function submitPaystackTransfer(Transfer $transfer): void
    {
        if (config('services.gateway.enabled', false)) {
            $this->submitGatewayTransfer($transfer);
            return;
        }
        if (! $transfer->paystack_recipient_code) {
            $transfer->update(['status' => 'FAILED', 'failed_at' => now(), 'failure_reason' => 'No recipient code']);

            return;
        }

        try {
            $response = $this->http->post("{$this->paystackBaseUrl}/transfer", [
                'headers' => [
                    'Authorization' => "Bearer {$this->paystackSecretKey}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'source' => 'balance',
                    'amount' => (int) round($transfer->amount_kobo / 100) * 100, // Paystack uses kobo
                    'recipient' => $transfer->paystack_recipient_code,
                    'reason' => $transfer->narration,
                    'reference' => $transfer->transfer_reference,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $transfer->update([
                'status' => 'PROCESSING',
                'paystack_transfer_code' => $data['data']['transfer_code'] ?? null,
                'provider_response' => $data['data'] ?? null,
            ]);

            // Commit the hold now that the transfer has successfully left our system
            $wallet = Wallet::find($transfer->wallet_id);
            if ($wallet) {
                app(WalletService::class)->commitHold(
                    $wallet,
                    $transfer->amount_kobo + $transfer->fee_kobo,
                    $transfer->transfer_reference,
                    "Bank transfer: {$transfer->account_number}"
                );
            }
        } catch (\Throwable $e) {
            $transfer->update([
                'status' => 'FAILED',
                'failed_at' => now(),
                'failure_reason' => $e->getMessage(),
            ]);
            
            // Release the hold without creating a ledger entry since the external API failed instantly
            $wallet = Wallet::find($transfer->wallet_id);
            if ($wallet) {
                app(WalletService::class)->releaseHold(
                    $wallet,
                    $transfer->amount_kobo + $transfer->fee_kobo
                );
            }
        }
    }

    private function submitGatewayTransfer(Transfer $transfer): void
    {
        try {
            $wallet = Wallet::find($transfer->wallet_id);
            $result = $this->gatewayClient->transferFromWallet(
                $wallet,
                $transfer->account_number,
                $transfer->bank_code,
                round($transfer->amount_kobo / 100, 2),
                $transfer->narration,
                reference: $transfer->transfer_reference,
            );
            $success = GatewayClient::isSuccess($result);
            $transfer->update([
                'status' => $success ? 'PROCESSING' : 'FAILED',
                'provider_response' => $result,
                'failed_at' => $success ? null : now(),
                'failure_reason' => $success ? null : ($result['message'] ?? 'Gateway transfer failed'),
            ]);

            $wallet = Wallet::find($transfer->wallet_id);
            if ($wallet) {
                if ($success) {
                    app(WalletService::class)->commitHold(
                        $wallet,
                        $transfer->amount_kobo + $transfer->fee_kobo,
                        $transfer->transfer_reference,
                        "Bank transfer: {$transfer->account_number}"
                    );
                } else {
                    app(WalletService::class)->releaseHold(
                        $wallet,
                        $transfer->amount_kobo + $transfer->fee_kobo
                    );
                }
            }
        } catch (\Throwable $e) {
            $transfer->update([
                'status' => 'FAILED',
                'failed_at' => now(),
                'failure_reason' => $e->getMessage(),
            ]);
            $wallet = Wallet::find($transfer->wallet_id);
            if ($wallet) {
                app(WalletService::class)->releaseHold(
                    $wallet,
                    $transfer->amount_kobo + $transfer->fee_kobo
                );
            }
        }
    }
}
