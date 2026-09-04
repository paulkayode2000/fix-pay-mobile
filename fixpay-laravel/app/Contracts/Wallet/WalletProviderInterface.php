<?php

namespace App\Contracts\Wallet;

interface WalletProviderInterface
{
    /**
     * Create a wallet account with the provider.
     * Returns provider-specific account data.
     */
    public function createAccount(string $accountName, string $bvn): array;

    /**
     * Get the wallet provider identifier.
     */
    public function getProviderName(): string;
}