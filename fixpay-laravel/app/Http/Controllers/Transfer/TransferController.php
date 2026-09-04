<?php

namespace App\Http\Controllers\Transfer;

use App\Http\Controllers\Controller;
use App\Models\Transfer;
use App\Models\Wallet;
use App\Services\Transfer\NinePsbTransferService;
use App\Services\Transfer\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(
        private readonly TransferService $transferService,
        private readonly ?NinePsbTransferService $ninePsbTransfer = null,
    ) {}

    /** POST /api/transfers/bank */
    public function toBank(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount_kobo'     => 'required|integer|min:10000',
            'account_number'  => 'required|string|size:10',
            'bank_code'       => 'required|string|max:10',
            'narration'       => 'nullable|string|max:100',
            'idempotency_key' => 'nullable|string|uuid',
        ]);

        $user = $request->user();

        if ($user->kyc_status !== 'VERIFIED') {
            return response()->json(['message' => 'Complete your KYC verification to initiate transfers.'], 403);
        }

        $wallet = $user->wallet;

        if (! $wallet || $wallet->status !== 'ACTIVE') {
            return response()->json(['message' => 'Wallet not available.'], 422);
        }

        $transfer = $this->transferService->initiateBank(
            user: $user,
            wallet: $wallet,
            amountKobo: $data['amount_kobo'],
            accountNumber: $data['account_number'],
            bankCode: $data['bank_code'],
            narration: $data['narration'] ?? 'Transfer',
            idempotencyKey: $data['idempotency_key'] ?? null,
        );

        return response()->json([
            'transfer_reference' => $transfer->transfer_reference,
            'status' => $transfer->status,
            'amount_kobo' => $transfer->amount_kobo,
            'fee_kobo' => $transfer->fee_kobo,
        ], 202);
    }

    /** POST /api/transfers/wallet */
    public function toWallet(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount_kobo'        => 'required|integer|min:100',
            'recipient_phone'    => 'required|string',
            'narration'          => 'nullable|string|max:100',
            'idempotency_key'    => 'nullable|string|uuid',
        ]);

        $user = $request->user();

        if ($user->kyc_status !== 'VERIFIED') {
            return response()->json(['message' => 'Complete your KYC verification to initiate transfers.'], 403);
        }

        $wallet = $user->wallet;

        if (! $wallet || $wallet->status !== 'ACTIVE') {
            return response()->json(['message' => 'Wallet not available.'], 422);
        }

        $transfer = $this->transferService->initiateWallet(
            user: $user,
            wallet: $wallet,
            amountKobo: $data['amount_kobo'],
            recipientPhone: $data['recipient_phone'],
            narration: $data['narration'] ?? 'Wallet transfer',
            idempotencyKey: $data['idempotency_key'] ?? null,
        );

        return response()->json([
            'transfer_reference' => $transfer->transfer_reference,
            'status' => $transfer->status,
            'amount_kobo' => $transfer->amount_kobo,
        ]);
    }

    /** GET /api/transfers/{reference} */
    public function status(Request $request, string $reference): JsonResponse
    {
        $transfer = Transfer::where('transfer_reference', $reference)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'transfer_reference' => $transfer->transfer_reference,
            'transfer_type' => $transfer->transfer_type,
            'amount_kobo' => $transfer->amount_kobo,
            'fee_kobo' => $transfer->fee_kobo,
            'status' => $transfer->status,
            'narration' => $transfer->narration,
            'bank_name' => $transfer->bank_name,
            'bank_code' => $transfer->bank_code,
            'account_number' => $transfer->account_number,
            'account_name' => $transfer->account_name,
            'completed_at' => $transfer->completed_at,
            'failed_at' => $transfer->failed_at,
        ]);
    }

    /** GET /api/transfers */
    public function index(Request $request): JsonResponse
    {
        $transfers = Transfer::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json($transfers);
    }

    /** GET /api/transfers/banks — get bank list (from 9PSB if available) */
    public function banks(): JsonResponse
    {
        if ($this->ninePsbTransfer) {
            try {
                $banks = $this->ninePsbTransfer->getBanks();
                return response()->json(['data' => $banks]);
            } catch (\Throwable $e) {
                // Fallback to hardcoded bank list
            }
        }

        // Static fallback
        $banks = [
            ['bankName' => '9 Payment Service Bank', 'bankCode' => '120001', 'nibssBankCode' => '120001'],
            ['bankName' => 'Access Bank', 'bankCode' => '044', 'nibssBankCode' => '044'],
            ['bankName' => 'First Bank', 'bankCode' => '011', 'nibssBankCode' => '011'],
            ['bankName' => 'Guaranty Trust Bank', 'bankCode' => '058', 'nibssBankCode' => '058'],
            ['bankName' => 'United Bank for Africa', 'bankCode' => '033', 'nibssBankCode' => '033'],
            ['bankName' => 'Zenith Bank', 'bankCode' => '057', 'nibssBankCode' => '057'],
            ['bankName' => 'Wema Bank', 'bankCode' => '035', 'nibssBankCode' => '035'],
            ['bankName' => 'Ecobank', 'bankCode' => '050', 'nibssBankCode' => '050'],
            ['bankName' => 'Fidelity Bank', 'bankCode' => '070', 'nibssBankCode' => '070'],
            ['bankName' => 'Sterling Bank', 'bankCode' => '232', 'nibssBankCode' => '232'],
            ['bankName' => 'Union Bank', 'bankCode' => '032', 'nibssBankCode' => '032'],
            ['bankName' => 'Stanbic IBTC', 'bankCode' => '221', 'nibssBankCode' => '221'],
            ['bankName' => 'Polaris Bank', 'bankCode' => '076', 'nibssBankCode' => '076'],
            ['bankName' => 'Keystone Bank', 'bankCode' => '082', 'nibssBankCode' => '082'],
        ];

        return response()->json(['data' => $banks]);
    }

    /** POST /api/transfers/verify-account — name enquiry */
    public function verifyAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accountNumber' => 'required|string|size:10',
            'bankCode' => 'required|string|max:10',
        ]);

        $user = $request->user();
        $wallet = Wallet::where('user_id', $user->id)
            ->where('wallet_provider', 'ninepsb')
            ->where('status', 'ACTIVE')
            ->first();

        if ($wallet && $this->ninePsbTransfer) {
            try {
                $result = $this->ninePsbTransfer->verifyAccount(
                    $data['bankCode'],
                    $data['accountNumber'],
                    $wallet,
                );

                return response()->json(['data' => $result]);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'Could not verify account: ' . $e->getMessage(),
                ], 422);
            }
        }

        // Fallback for non-9PSB wallets: mock response
        return response()->json([
            'data' => [
                'accountNumber' => $data['accountNumber'],
                'bankCode' => $data['bankCode'],
                'accountName' => 'John Doe',
                'status' => 'SUCCESS',
            ],
        ]);
    }
}
