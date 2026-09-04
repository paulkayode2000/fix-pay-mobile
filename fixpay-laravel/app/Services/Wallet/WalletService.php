<?php

namespace App\Services\Wallet;

use App\Models\AppUser;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\NinePsb\NinePsbWalletProvider;
use App\Services\Providus\ProvidusVirtualAccountAdapter;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function __construct(
        private readonly ProvidusVirtualAccountAdapter $virtualAccount,
        private readonly ?NinePsbWalletProvider $ninePsbProvider = null,
    ) {}

    /**
     * Create a wallet with the default provider (Providus).
     */
    public function createWallet(AppUser $user): Wallet
    {
        return DB::transaction(function () use ($user) {
            // TEMPORARY: Bypassed real Providus Virtual Account creation for testing.
            // Uncomment the lines below to re-enable real wallet creation.
            /*
            $vaData = $this->virtualAccount->createAccount(
                "{$user->first_name} {$user->last_name}",
                '' // BVN provided after KYC
            );
            */

            // Mocked virtual account data for testing
            $vaData = [
                'account_number' => '9' . str_pad((string) random_int(100_000_000, 999_999_999), 9, '0'),
                'bank' => 'Providus Bank',
                'bank_code' => '101',
                'reference' => 'mock_va_' . time(),
            ];

            return Wallet::create([
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'balance_kobo' => 0,
                'ledger_balance_kobo' => 0,
                'currency' => 'NGN',
                'status' => 'ACTIVE',
                'wallet_provider' => 'providus',
                'virtual_account_number' => $vaData['account_number'],
                'virtual_account_bank' => $vaData['bank'],
                'virtual_account_bank_code' => $vaData['bank_code'],
                'virtual_account_reference' => $vaData['reference'],
            ]);
        });
    }

    /**
     * Create a wallet using 9PSB WAAS API.
     * Requires BVN or NIN per 9PSB spec.
     */
    public function createNinePsbWallet(AppUser $user, ?string $bvn = null, ?string $nin = null, ?string $ninUserId = null): Wallet
    {
        if (!$this->ninePsbProvider) {
            throw new \RuntimeException('9PSB wallet provider is not configured.');
        }

        $accountName = "{$user->first_name} {$user->last_name}";

        $vaData = $this->ninePsbProvider->createAccount(
            accountName: $accountName,
            bvn: $bvn,
            nin: $nin,
            ninUserId: $ninUserId,
            user: $user,
        );

        return DB::transaction(function () use ($user, $vaData) {
            return Wallet::create([
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'balance_kobo' => 0,
                'ledger_balance_kobo' => 0,
                'currency' => 'NGN',
                'status' => 'ACTIVE',
                'wallet_provider' => 'ninepsb',
                'virtual_account_number' => $vaData['account_number'],
                'virtual_account_bank' => $vaData['bank'],
                'virtual_account_bank_code' => $vaData['bank_code'],
                'virtual_account_reference' => $vaData['reference'],
                'ninepsb_account_number' => $vaData['account_number'],
                'ninepsb_customer_id' => $vaData['customer_id'],
                'ninepsb_order_ref' => $vaData['order_ref'],
                'ninepsb_tier' => '1',
                'ninepsb_metadata' => [
                    'full_name' => $vaData['full_name'],
                    'raw_response' => $vaData['raw'] ?? null,
                ],
            ]);
        });
    }

    /**
     * Debit a wallet within an existing transaction.
     * Caller MUST wrap in DB::transaction().
     */
    public function debit(Wallet $wallet, int $amountKobo, string $correlationId, string $description): LedgerEntry
    {
        if (! $wallet->hasSufficientBalance($amountKobo)) {
            throw new \RuntimeException("Insufficient balance. Available: {$wallet->balance_kobo} kobo, Required: {$amountKobo} kobo.");
        }

        $wallet->lockForUpdate()->find($wallet->id); // pessimistic lock

        $newBalance = $wallet->balance_kobo - $amountKobo;

        $wallet->update([
            'balance_kobo' => $newBalance,
            'ledger_balance_kobo' => $wallet->ledger_balance_kobo - $amountKobo,
        ]);

        return LedgerEntry::create([
            'wallet_id' => $wallet->id,
            'entry_type' => 'DEBIT',
            'amount_kobo' => $amountKobo,
            'running_balance_kobo' => $newBalance,
            'correlation_id' => $correlationId,
            'description' => $description,
            'currency' => $wallet->currency,
        ]);
    }

    /**
     * Credit a wallet within an existing transaction.
     * Caller MUST wrap in DB::transaction().
     */
    public function credit(Wallet $wallet, int $amountKobo, string $correlationId, string $description): LedgerEntry
    {
        $wallet->lockForUpdate()->find($wallet->id);

        $newBalance = $wallet->balance_kobo + $amountKobo;

        $wallet->update([
            'balance_kobo' => $newBalance,
            'ledger_balance_kobo' => $wallet->ledger_balance_kobo + $amountKobo,
        ]);

        return LedgerEntry::create([
            'wallet_id' => $wallet->id,
            'entry_type' => 'CREDIT',
            'amount_kobo' => $amountKobo,
            'running_balance_kobo' => $newBalance,
            'correlation_id' => $correlationId,
            'description' => $description,
            'currency' => $wallet->currency,
        ]);
    }

    /**
     * Reverse a previously debited amount (e.g., failed payment).
     */
    public function reverse(Wallet $wallet, int $amountKobo, string $correlationId, string $description): LedgerEntry
    {
        return $this->credit($wallet, $amountKobo, $correlationId, "REVERSAL: {$description}");
    }

    /**
     * Temporarily hold funds (deduct available balance) without writing to the ledger.
     */
    public function hold(Wallet $wallet, int $amountKobo): void
    {
        if (! $wallet->hasSufficientBalance($amountKobo)) {
            throw new \RuntimeException("Insufficient balance. Available: {$wallet->balance_kobo} kobo, Required: {$amountKobo} kobo.");
        }

        $wallet->lockForUpdate()->find($wallet->id);

        $wallet->update([
            'balance_kobo' => $wallet->balance_kobo - $amountKobo,
        ]);
    }

    /**
     * Finalize a hold by writing the ledger entry and deducting ledger balance.
     */
    public function commitHold(Wallet $wallet, int $amountKobo, string $correlationId, string $description): LedgerEntry
    {
        $wallet->lockForUpdate()->find($wallet->id);

        $newLedgerBalance = $wallet->ledger_balance_kobo - $amountKobo;

        $wallet->update([
            'ledger_balance_kobo' => $newLedgerBalance,
        ]);

        return LedgerEntry::create([
            'wallet_id' => $wallet->id,
            'entry_type' => 'DEBIT',
            'amount_kobo' => $amountKobo,
            'running_balance_kobo' => $wallet->balance_kobo, // Reflect current available balance
            'correlation_id' => $correlationId,
            'description' => $description,
            'currency' => $wallet->currency,
        ]);
    }

    /**
     * Release a hold by restoring the available balance (no ledger entry).
     */
    public function releaseHold(Wallet $wallet, int $amountKobo): void
    {
        $wallet->lockForUpdate()->find($wallet->id);

        $wallet->update([
            'balance_kobo' => $wallet->balance_kobo + $amountKobo,
        ]);
    }
}
