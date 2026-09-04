<?php

namespace App\Http\Controllers\Wallet;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Favourite;
use App\Models\KycVerification;
use App\Models\LedgerEntry;
use App\Models\NinePsbTransaction;
use App\Models\Transfer;
use App\Models\VtpassPayment;
use App\Models\Wallet;
use App\Services\NinePsb\NinePsbAdapter;
use App\Services\Security\TransactionGuardService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles 9PSB wallet operations with full security guardrails:
 * - KYC-gated wallet creation (BVN or NIN verified)
 * - Device binding (one wallet, one device)
 * - Location enforcement for all transactions
 * - Anti-replay (nonce + timestamp validation)
 * - Idempotency (duplicate prevention)
 * - MITM protection (request signing)
 */
class NinePsbWalletController extends Controller
{
    public function __construct(
        private readonly NinePsbAdapter $ninePsb,
        private readonly WalletService $walletService,
        private readonly TransactionGuardService $guard,
    ) {}

    // ─────────────────────────────────────────────────────────────────
    // TERMS & CONDITIONS
    // ─────────────────────────────────────────────────────────────────

    /**
     * GET /wallet/ninepsb/terms
     * Returns 9PSB terms & conditions that the user must accept before wallet creation.
     */
    public function terms(): \Illuminate\Http\JsonResponse
    {
        $terms = config('ninepsb.terms', [
            'version' => '1.0',
            'last_updated' => '2024-01-01',
            'title' => '9PSB Wallet Terms & Conditions',
            'content' => 'Terms of service for 9 Payment Service Bank wallet.',
        ]);

        return response()->json([
            'status' => 'SUCCESS',
            'data' => [
                'title' => $terms['title'],
                'content' => $terms['content'],
                'version' => $terms['version'],
                'last_updated' => $terms['last_updated'],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // WALLET CREATION (KYC-gated + T&C accepted)
    // ─────────────────────────────────────────────────────────────────

    /**
     * POST /wallet/ninepsb/create
     * 
     * Creates a 9PSB wallet. Requires:
     *   - BVN or NIN verified in kyc_verifications
     *   - Terms & Conditions accepted (terms_accepted = true)
     *   - Device ID + Location + Anti-replay headers
     * 
     * On success:
     *   - Purges all Providus data (ledger, transfers, payments, disputes, favourites)
     *   - Closes + zeroes Providus wallet
     *   - Creates new 9PSB wallet
     *   - Binds wallet to device
     */
    public function create(Request $request): \Illuminate\Http\JsonResponse
    {
        // ── Idempotency check ────────────────────────────────────────
        $idempotencyKey = $request->header('X-Idempotency-Key', 
            'FP-CREATE-' . $request->user()->id . '-' . now()->timestamp);
        
        $duplicate = $this->guard->checkIdempotency($idempotencyKey);
        if ($duplicate) {
            return response()->json([
                'status' => 'DUPLICATE',
                'message' => $duplicate['message'],
                'data' => $duplicate['original_response'] ?? [],
            ], 409);
        }

        $user = $request->user();

        // ── T&C Gate: Must accept terms ─────────────────────────────
        $request->validate([
            'terms_accepted' => 'required|accepted',
        ]);

        $termsVersion = config('ninepsb.terms.version', '1.0');

        // ── KYC Gate: BVN or NIN must be verified ───────────────────
        $bvnVerified = KycVerification::where('user_id', $user->id)
            ->where('type', 'BVN')
            ->where('verification_status', 'VERIFIED')
            ->exists();

        $ninVerified = KycVerification::where('user_id', $user->id)
            ->where('type', 'NIN')
            ->where('verification_status', 'VERIFIED')
            ->exists();

        if (!$bvnVerified && !$ninVerified) {
            return response()->json([
                'status' => 'FAILED',
                'message' => 'KYC required. Verify your BVN or NIN before creating a 9PSB wallet.',
                'required_actions' => [
                    'verify_bvn' => 'POST /kyc/bvn',
                    'verify_nin' => 'POST /kyc/nin',
                ],
            ], 422);
        }

        // ── Check for existing 9PSB wallet ──────────────────────────
        $existingNinePsb = Wallet::where('user_id', $user->id)
            ->where('wallet_provider', 'ninepsb')
            ->first();
        if ($existingNinePsb) {
            return response()->json([
                'status' => 'FAILED',
                'message' => 'You already have a 9PSB wallet.',
                'data' => [
                    'wallet_id' => $existingNinePsb->id,
                    'account_number' => $existingNinePsb->ninepsb_account_number,
                ],
            ], 409);
        }

        // ── Security: device binding ────────────────────────────────
        $deviceId = $request->header('X-Device-ID');
        if (!$deviceId) {
            return response()->json(['status' => 'FAILED', 'message' => 'Device ID required.'], 400);
        }

        // ── Security: location ──────────────────────────────────────
        $lat = $request->header('X-Location-Lat');
        $lng = $request->header('X-Location-Lng');
        if (!$lat || !$lng) {
            return response()->json(['status' => 'FAILED', 'message' => 'Location required.'], 400);
        }

        $request->validate([
            'bvn' => 'sometimes|string|size:11',
            'nin' => 'sometimes|string|size:11',
            'nin_user_id' => 'sometimes|string',
        ]);

        // ── Auto-retrieve BVN/NIN from encrypted KYC storage ──────
        $bvnValue = $request->input('bvn') ?: null;
        $ninValue = $request->input('nin') ?: null;
        $ninUserIdValue = $request->input('nin_user_id') ?: null;

        // If BVN not in request but verified in DB, decrypt from storage
        if (!$bvnValue && $bvnVerified) {
            $bvnRecord = KycVerification::where('user_id', $user->id)
                ->where('type', 'BVN')
                ->where('verification_status', 'VERIFIED')
                ->whereNotNull('encrypted_identifier')
                ->first();
            if ($bvnRecord) {
                $bvnValue = Crypt::decryptString($bvnRecord->encrypted_identifier);
            }
        }

        // If NIN not in request but verified in DB, decrypt from storage
        if (!$ninValue && $ninVerified && !$bvnValue) {
            $ninRecord = KycVerification::where('user_id', $user->id)
                ->where('type', 'NIN')
                ->where('verification_status', 'VERIFIED')
                ->whereNotNull('encrypted_identifier')
                ->first();
            if ($ninRecord) {
                $ninValue = Crypt::decryptString($ninRecord->encrypted_identifier);
            }
        }

        // Still need at least one identifier
        if (!$bvnValue && !$ninValue) {
            return response()->json([
                'status' => 'FAILED',
                'message' => 'Please provide your BVN or NIN.',
            ], 422);
        }

        // ── Log: entering creation with resolved identifiers ──────
        Log::info('9PSB Create: Starting wallet creation', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'has_bvn' => !empty($bvnValue),
            'bvn_masked' => $bvnValue ? substr($bvnValue, 0, 3) . '****' : null,
            'has_nin' => !empty($ninValue),
            'nin_masked' => $ninValue ? substr($ninValue, 0, 3) . '****' : null,
            'idempotency_key' => $idempotencyKey,
            'device_id_provided' => !empty($deviceId),
            'location' => ['lat' => $lat, 'lng' => $lng],
        ]);

        try {
            $wallet = DB::transaction(function () use ($user, $deviceId, $lat, $lng, $termsVersion, $bvnValue, $ninValue, $ninUserIdValue, $idempotencyKey) {
                // ── STEP 1: PURGE PROVIDUS DATA ─────────────────────
                $oldWallet = Wallet::where('user_id', $user->id)
                    ->where('wallet_provider', 'providus')
                    ->first();

                if ($oldWallet) {
                    // Delete all financial history tied to the Providus wallet
                    $deletedLedger = LedgerEntry::where('wallet_id', $oldWallet->id)->delete();
                    $deletedTransfers = Transfer::where('wallet_id', $oldWallet->id)->delete();
                    $deletedVtpass = VtpassPayment::where('wallet_id', $oldWallet->id)->delete();

                    Log::info('9PSB Onboarding: Purged Providus data', [
                        'user_id' => $user->id,
                        'wallet_id' => $oldWallet->id,
                        'ledger_entries_deleted' => $deletedLedger,
                        'transfers_deleted' => $deletedTransfers,
                        'vtpass_payments_deleted' => $deletedVtpass,
                    ]);

                    // Close and zero the Providus wallet
                    $oldWallet->update([
                        'status' => 'CLOSED',
                        'balance_kobo' => 0,
                        'ledger_balance_kobo' => 0,
                    ]);
                }

                // ── STEP 2: DELETE USER-LEVEL DATA ──────────────────
                $deletedDisputes = Dispute::where('user_id', $user->id)->delete();
                $deletedFavourites = Favourite::where('user_id', $user->id)->delete();

                Log::info('9PSB Onboarding: Purged user data', [
                    'user_id' => $user->id,
                    'disputes_deleted' => $deletedDisputes,
                    'favourites_deleted' => $deletedFavourites,
                ]);

                // ── STEP 3: CREATE 9PSB WALLET ──────────────────────
                Log::info('9PSB Create: Calling WalletService->createNinePsbWallet', [
                    'user_id' => $user->id,
                    'bvn_length' => $bvnValue ? strlen($bvnValue) : 0,
                    'nin_length' => $ninValue ? strlen($ninValue) : 0,
                ]);

                $wallet = $this->walletService->createNinePsbWallet(
                    user: $user,
                    bvn: $bvnValue,
                    nin: $ninValue,
                    ninUserId: $ninUserIdValue,
                );

                // ── STEP 4: STORE T&C ACCEPTANCE ────────────────────
                $metadata = $wallet->ninepsb_metadata ?? [];
                $metadata['terms_accepted'] = true;
                $metadata['terms_accepted_at'] = now()->toIso8601String();
                $metadata['terms_version'] = $termsVersion;
                $wallet->update(['ninepsb_metadata' => $metadata]);

                // ── STEP 5: BIND DEVICE ─────────────────────────────
                $this->guard->bindDevice($wallet, $deviceId);

                // ── STEP 6: RECORD LOCATION ─────────────────────────
                $this->guard->recordLocation($wallet, (float) $lat, (float) $lng);

                // ── STEP 7: UPDATE USER STATUS ──────────────────────
                $user->update(['kyc_status' => 'VERIFIED', 'tier' => 1]);

                Log::info('9PSB Create: Wallet created successfully', [
                    'user_id' => $user->id,
                    'account_number' => $wallet->ninepsb_account_number,
                    'terms_version' => $termsVersion,
                ]);

                return $wallet;
            });

            // ── Record idempotency ──────────────────────────────────
            $response = [
                'status' => 'SUCCESS',
                'message' => '9PSB Wallet created successfully.',
                'data' => [
                    'wallet_id' => $wallet->id,
                    'account_number' => $wallet->ninepsb_account_number,
                    'account_name' => $wallet->ninepsb_metadata['full_name'] ?? null,
                    'bank' => $wallet->virtual_account_bank,
                    'bank_code' => $wallet->virtual_account_bank_code,
                    'tier' => $wallet->ninepsb_tier,
                    'balance_kobo' => $wallet->balance_kobo,
                    'device_bound' => true,
                    'terms_accepted' => true,
                    'terms_version' => $termsVersion,
                ],
            ];
            $this->guard->recordIdempotency($idempotencyKey, $response);

            return response()->json($response, 201);

        } catch (\Throwable $e) {
            Log::error('9PSB Create: Wallet creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'FAILED',
                'message' => 'Failed to create 9PSB wallet: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // WALLET ENQUIRY
    // ─────────────────────────────────────────────────────────────────

    /**
     * GET /wallet/ninepsb/enquiry
     * Fetches balance + details from 9PSB. Requires device binding + location.
     */
    public function enquiry(Request $request): \Illuminate\Http\JsonResponse
    {
        $wallet = $this->resolveNinePsbWallet($request);

        try {
            // ── Security guardrails ─────────────────────────────────
            $this->guard->enforce($wallet, $request);

            // ── Update location ─────────────────────────────────────
            $lat = $request->header('X-Location-Lat');
            $lng = $request->header('X-Location-Lng');
            if ($lat && $lng) {
                $this->guard->recordLocation($wallet, (float) $lat, (float) $lng);
            }

            $response = $this->ninePsb->walletEnquiry($wallet->ninepsb_account_number);
            $data = $response['data'] ?? [];

            // Sync local balance with 9PSB
            if (isset($data['availableBalance'])) {
                $availableBalanceNaira = (float) $data['availableBalance'];
                $availableBalanceKobo = (int) round($availableBalanceNaira * 100);

                $wallet->update([
                    'balance_kobo' => $availableBalanceKobo,
                    'ledger_balance_kobo' => isset($data['ledgerBalance'])
                        ? (int) round(((float) $data['ledgerBalance']) * 100)
                        : $availableBalanceKobo,
                ]);
            }

            return response()->json([
                'status' => 'SUCCESS',
                'message' => $response['message'] ?? 'Wallet enquiry successful',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('9PSB: Wallet enquiry failed', [
                'account_number' => $wallet->ninepsb_account_number,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'FAILED',
                'message' => 'Failed to fetch wallet details: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // TRANSACTION HISTORY
    // ─────────────────────────────────────────────────────────────────

    /**
     * GET /wallet/ninepsb/transactions
     */
    public function transactions(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'from_date' => 'required|date|date_format:Y-m-d',
            'to_date' => 'required|date|date_format:Y-m-d|after_or_equal:from_date',
            'number_of_items' => 'sometimes|integer|min:1|max:1000',
        ]);

        $wallet = $this->resolveNinePsbWallet($request);

        try {
            $this->guard->enforce($wallet, $request);

            $response = $this->ninePsb->walletTransactions(
                $wallet->ninepsb_account_number,
                $request->input('from_date'),
                $request->input('to_date'),
                (string) $request->input('number_of_items', 100),
            );

            return response()->json([
                'status' => 'SUCCESS',
                'message' => $response['message'] ?? 'Transaction history',
                'data' => $response['data'] ?? [],
            ]);
        } catch (\Throwable $e) {
            Log::error('9PSB: Transaction history fetch failed', [
                'account_number' => $wallet->ninepsb_account_number,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'FAILED',
                'message' => 'Failed to fetch transactions: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // WALLET STATUS
    // ─────────────────────────────────────────────────────────────────

    /**
     * GET /wallet/ninepsb/status
     */
    public function status(Request $request): \Illuminate\Http\JsonResponse
    {
        $wallet = $this->resolveNinePsbWallet($request);

        try {
            $this->guard->enforce($wallet, $request);

            $response = $this->ninePsb->walletStatus($wallet->ninepsb_account_number);

            return response()->json([
                'status' => 'SUCCESS',
                'message' => $response['message'] ?? 'Wallet status enquiry successful',
                'data' => $response['data'] ?? [],
            ]);
        } catch (\Throwable $e) {
            Log::error('9PSB: Wallet status check failed', [
                'account_number' => $wallet->ninepsb_account_number,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'FAILED',
                'message' => 'Failed to check wallet status: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // WALLET UPGRADE
    // ─────────────────────────────────────────────────────────────────

    /**
     * GET /wallet/ninepsb/upgrade-status
     */
    public function upgradeStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        $wallet = $this->resolveNinePsbWallet($request);

        try {
            $this->guard->enforce($wallet, $request);

            $response = $this->ninePsb->upgradeStatus($wallet->ninepsb_account_number);
            $data = $response['data'] ?? [];

            if (isset($data['tier'])) {
                $wallet->update(['ninepsb_tier' => (string) $data['tier']]);
                $wallet->user->update(['tier' => (int) $data['tier']]);
            }

            return response()->json([
                'status' => 'SUCCESS',
                'message' => $response['message'] ?? 'Upgrade status',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('9PSB: Upgrade status check failed', [
                'account_number' => $wallet->ninepsb_account_number,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'FAILED',
                'message' => 'Failed to check upgrade status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /wallet/ninepsb/upgrade
     */
    public function upgrade(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'bvn' => 'required|string|size:11',
            'nin' => 'required|string',
            'tier' => 'required|in:2,3',
            'email' => 'required|email',
            'userPhoto' => 'required|string',
            'idType' => 'required|in:1,2,3,4',
            'idNumber' => 'required|string',
            'idIssueDate' => 'required|date_format:Y-m-d',
            'houseNumber' => 'required|string',
            'streetName' => 'required|string',
            'state' => 'required|string',
            'city' => 'required|string',
            'localGovernment' => 'required|string',
            'pep' => 'required|in:YES,NO',
            'customerSignature' => 'required|string',
            'utilityBill' => 'required|string',
            'nearestLandmark' => 'required|string',
            'idExpiryDate' => 'sometimes|date_format:Y-m-d|nullable',
            'idCardFront' => 'required|string',
            'idCardBack' => 'sometimes|string|nullable',
            'placeOfBirth' => 'sometimes|string|nullable',
            'proofOfAddressVerification' => 'sometimes|string|nullable',
        ]);

        $wallet = $this->resolveNinePsbWallet($request);

        try {
            $this->guard->enforce($wallet, $request);

            $payload = array_merge($request->only([
                'bvn', 'nin', 'tier', 'email', 'userPhoto', 'idType', 'idNumber',
                'idIssueDate', 'idExpiryDate', 'idCardFront', 'idCardBack',
                'houseNumber', 'streetName', 'state', 'city', 'localGovernment',
                'pep', 'customerSignature', 'utilityBill', 'nearestLandmark',
                'placeOfBirth', 'proofOfAddressVerification',
            ]), [
                'accountNumber' => $wallet->ninepsb_account_number,
                'accountName' => $wallet->ninepsb_metadata['full_name'] ?? '',
                'phoneNumber' => $wallet->user?->phone ?? '',
            ]);

            $response = $this->ninePsb->walletUpgrade($payload);

            $metadata = $wallet->ninepsb_metadata ?? [];
            $metadata['upgrade_submitted_at'] = now()->toIso8601String();
            $metadata['upgrade_tier_requested'] = $request->input('tier');
            $wallet->update(['ninepsb_metadata' => $metadata]);

            return response()->json([
                'status' => 'SUCCESS',
                'message' => $response['message'] ?? 'Wallet upgrade request submitted',
                'data' => $response['data'] ?? [],
            ]);
        } catch (\Throwable $e) {
            Log::error('9PSB: Wallet upgrade failed', [
                'account_number' => $wallet->ninepsb_account_number,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'FAILED',
                'message' => 'Failed to upgrade wallet: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // TRANSACTION REQUERY (TSQ)
    // ─────────────────────────────────────────────────────────────────

    /**
     * POST /wallet/ninepsb/requery
     */
    public function requery(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'transaction_id' => 'required|string|max:25',
            'amount' => 'sometimes|numeric',
            'transaction_type' => 'sometimes|string',
        ]);

        $wallet = $this->resolveNinePsbWallet($request);

        try {
            $this->guard->enforce($wallet, $request);

            $response = $this->ninePsb->walletRequery(
                $request->input('transaction_id'),
                $request->input('amount') ? (float) $request->input('amount') : null,
                $request->input('transaction_type'),
                null,
                $wallet->ninepsb_account_number,
            );

            $transactionId = $request->input('transaction_id');
            $localTransaction = NinePsbTransaction::where('transaction_id', $transactionId)->first();

            if ($localTransaction) {
                $data = $response['data'] ?? [];
                $localTransaction->update([
                    'status' => $response['status'] === 'SUCCESS' ? 'SUCCESS' : 'FAILED',
                    'response_code' => $data['responseCode'] ?? $response['responseCode'] ?? null,
                    'response_message' => $data['responseMessage'] ?? $response['message'] ?? null,
                    'response_payload' => $response,
                ]);
            }

            return response()->json([
                'status' => 'SUCCESS',
                'message' => $response['message'] ?? 'Transaction status',
                'data' => $response['data'] ?? [],
            ]);
        } catch (\Throwable $e) {
            Log::error('9PSB: Transaction requery failed', [
                'transaction_id' => $request->input('transaction_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'FAILED',
                'message' => 'Failed to query transaction: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Resolve the user's 9PSB wallet, or return 404.
     */
    private function resolveNinePsbWallet(Request $request): Wallet
    {
        $user = $request->user();
        $wallet = Wallet::where('user_id', $user->id)
            ->where('wallet_provider', 'ninepsb')
            ->first();

        if (!$wallet || !$wallet->ninepsb_account_number) {
            abort(404, 'No 9PSB wallet found. Create one at POST /wallet/ninepsb/create');
        }

        return $wallet;
    }
}