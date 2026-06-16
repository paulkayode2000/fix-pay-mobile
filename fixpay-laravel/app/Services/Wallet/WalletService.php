<?php

namespace App\Services\Wallet;

use App\Models\AppUser;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\Providus\ProvidusVirtualAccountAdapter;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function __construct(
        private readonly ProvidusVirtualAccountAdapter $virtualAccount,
    ) {}

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
                'virtual_account_number' => $vaData['account_number'],
                'virtual_account_bank' => $vaData['bank'],
                'virtual_account_bank_code' => $vaData['bank_code'],
                'virtual_account_reference' => $vaData['reference'],
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
