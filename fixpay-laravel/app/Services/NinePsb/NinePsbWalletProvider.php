<?php

namespace App\Services\NinePsb;

use App\Contracts\Wallet\WalletProviderInterface;
use App\Services\Gateway\GatewayClient;
use App\Models\AppUser;
use Illuminate\Support\Facades\Log;

/**
 * Implements wallet creation via 9PSB WAAS API.
 */
class NinePsbWalletProvider implements WalletProviderInterface
{
    public function __construct(
        private readonly NinePsbAdapter $adapter,
        private readonly GatewayClient $gatewayClient,
    ) {}

    public function getProviderName(): string
    {
        return 'ninepsb';
    }

    /**
     * Create a 9PSB wallet for a given user.
     *
     * @param string $accountName Full name for the account
     * @param string|null $bvn    Bank Verification Number (11 digits)
     * @param string|null $nin    National Identification Number (11 digits)
     * @param string|null $ninUserId Unique user ID for NIN (from NIMC app or *346*2*NIN#)
     * @param AppUser|null $user  Optional user for additional KYC fields
     * @return array Account details from 9PSB response
     */
    public function createAccount(
        string $accountName,
        ?string $bvn = null,
        ?string $nin = null,
        ?string $ninUserId = null,
        ?AppUser $user = null,
    ): array {
        $nameParts = explode(' ', $accountName, 2);
        $lastName = $nameParts[0] ?? $accountName;
        $otherNames = $nameParts[1] ?? '';

        $payload = [
            'lastName' => $lastName,
            'otherNames' => $otherNames,
            'accountName' => $accountName,
            'phoneNo' => $user?->phone ?? '',
            'gender' => $user?->gender === 'female' ? 1 : 0,
            'dateOfBirth' => $user?->date_of_birth?->format('d/m/Y') ?? '',
            'address' => $user?->address ?? 'Not Provided',
            'email' => $user?->email ?? '',
        ];

        // Per 9PSB spec: Either BVN or NIN must be provided for tier-1 wallet
        if ($bvn) {
            $payload['bvn'] = $bvn;
        }

        if ($nin) {
            $payload['nationalIdentityNo'] = $nin;
            if ($ninUserId) {
                $payload['ninUserId'] = $ninUserId;
            }
        } elseif ($user?->national_identity_no) {
            $payload['nationalIdentityNo'] = $user->national_identity_no;
        }

        Log::info('9PSB: Opening wallet', [
            'account_name' => $accountName,
            'user_id' => $user?->id,
            'has_bvn' => !empty($bvn),
            'has_nin' => !empty($nin),
        ]);

        // Canonical reference for the risk-gate identity event (wallet_open).
        // Stable per user + attempt so TMS upserts by ref_no; the gateway only
        // uses it for the risk gate — the 9PSB orderRef comes back in the response.
        $walletOpenRef = 'WOP-' . now()->format('YmdHis') . '-' . ($user?->getKey() ?? 'anon');

        $response = config('services.gateway.enabled', false)
            ? $this->gatewayClient->openWallet('9psb', $payload, $walletOpenRef)
            : $this->adapter->openWallet($payload);

        $data = $response['data'] ?? [];

        $result = [
            'account_number' => $data['accountNumber'] ?? null,
            'customer_id' => $data['customerID'] ?? null,
            'order_ref' => $data['orderRef'] ?? null,
            'full_name' => $data['fullName'] ?? null,
            'bank' => '9 Payment Service Bank',
            'bank_code' => '120001',
            'reference' => $data['orderRef'] ?? null,
            'provider' => 'ninepsb',
            'raw' => $response,
        ];

        Log::info('9PSB: Wallet opened successfully', [
            'account_number' => $result['account_number'],
            'customer_id' => $result['customer_id'],
        ]);

        return $result;
    }
}