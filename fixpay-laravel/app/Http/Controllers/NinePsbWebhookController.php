<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Services\NinePsb\NinePsbAdapter;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles 9PSB webhook notifications for:
 * - Inflow credit/transfer (event=transfer)
 * - Account upgrade (event=account-upgrade)
 * 
 * Webhooks are authenticated via Basic Auth.
 */
class NinePsbWebhookController extends Controller
{
    public function __construct(
        private readonly NinePsbAdapter $ninePsb,
        private readonly WalletService $walletService,
    ) {}

    /**
     * Handle all 9PSB webhook events.
     * GET/POST /webhooks/9psb?event=transfer|account-upgrade
     */
    public function handle(Request $request): \Illuminate\Http\JsonResponse
    {
        // Validate Basic Auth
        if (!$this->validateBasicAuth($request)) {
            Log::warning('9PSB Webhook: Invalid Basic Auth credentials', [
                'ip' => $request->ip(),
            ]);
            return response()->json([
                'success' => false,
                'code' => '74',
                'status' => 'FAILED',
                'message' => 'Invalid message signature',
            ], 401);
        }

        $event = $request->query('event', 'transfer');

        Log::info('9PSB Webhook received', [
            'event' => $event,
            'payload' => $request->all(),
        ]);

        return match ($event) {
            'transfer' => $this->handleTransfer($request),
            'account-upgrade' => $this->handleAccountUpgrade($request),
            default => response()->json([
                'success' => false,
                'code' => '96',
                'status' => 'FAILED',
                'message' => "Unknown event type: {$event}",
            ], 400),
        };
    }

    /**
     * Handle inflow credit/transfer notification.
     * 
     * 9PSB sends a webhook when a wallet receives an inflow (e.g., transfer from another bank).
     * We MUST call notification_requery to confirm before crediting.
     */
    private function handleTransfer(Request $request): \Illuminate\Http\JsonResponse
    {
        $payload = $request->all();

        // Extract identifiers - 9PSB sends in either short or long format
        $sessionId = $payload['nipsessionid']
            ?? $payload['transaction']['externalreference']
            ?? $payload['orderref']
            ?? null;

        $accountNumber = $payload['accountnumber']
            ?? $payload['customer']['account']['number']
            ?? null;

        $amount = $payload['amount'] ?? null;
        $narration = $payload['narration'] ?? 'Inflow credit';

        if (!$sessionId || !$accountNumber) {
            Log::error('9PSB Webhook: Missing session ID or account number in transfer payload', $payload);
            return response()->json([
                'success' => false,
                'code' => '96',
                'status' => 'FAILED',
                'message' => 'Missing required fields: sessionID or accountNumber',
            ], 400);
        }

        // Find the local wallet by 9PSB account number
        $wallet = Wallet::where('ninepsb_account_number', $accountNumber)->first();

        if (!$wallet) {
            Log::warning('9PSB Webhook: Wallet not found for account', [
                'account_number' => $accountNumber,
            ]);
            // Still acknowledge the webhook to prevent retries
            return $this->acknowledge();
        }

        // Requery the notification to confirm it's genuine
        try {
            $requeryResponse = $this->ninePsb->notificationRequery($sessionId, $accountNumber);

            Log::info('9PSB Webhook: Notification requery result', [
                'session_id' => $sessionId,
                'account_number' => $accountNumber,
                'response' => $requeryResponse,
            ]);

            // Check if requery was successful
            $responseCode = $requeryResponse['responseCode'] ?? $requeryResponse['data']['responseCode'] ?? null;

            if ($responseCode !== '00') {
                Log::warning('9PSB Webhook: Requery did not return success', [
                    'response_code' => $responseCode,
                ]);
                return $this->acknowledge();
            }

            // Convert amount to kobo and credit the wallet
            $amountNaira = (float) $amount;
            $amountKobo = (int) round($amountNaira * 100);

            if ($amountKobo > 0) {
                $this->walletService->credit(
                    $wallet,
                    $amountKobo,
                    $sessionId,
                    "9PSB Inflow: {$narration}"
                );

                Log::info('9PSB Webhook: Wallet credited', [
                    'wallet_id' => $wallet->id,
                    'amount_kobo' => $amountKobo,
                    'account_number' => $accountNumber,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('9PSB Webhook: Error processing transfer webhook', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);
            // Still acknowledge - we'll reconcile later
        }

        return $this->acknowledge();
    }

    /**
     * Handle account upgrade webhook notification.
     */
    private function handleAccountUpgrade(Request $request): \Illuminate\Http\JsonResponse
    {
        $payload = $request->all();

        $accountNumber = $payload['accountNumber'] ?? null;
        $status = $payload['status'] ?? null;
        $message = $payload['message'] ?? '';

        if (!$accountNumber) {
            Log::error('9PSB Webhook: Missing account number in upgrade payload', $payload);
            return $this->acknowledge();
        }

        $wallet = Wallet::where('ninepsb_account_number', $accountNumber)->first();

        if ($wallet && $status === 'Approved') {
            // Query the upgrade status to get the new tier
            try {
                $upgradeStatus = $this->ninePsb->upgradeStatus($accountNumber);
                $data = $upgradeStatus['data'] ?? [];
                $newTier = $data['tier'] ?? null;

                if ($newTier) {
                    $metadata = $wallet->ninepsb_metadata ?? [];
                    $metadata['upgrade_status'] = $status;
                    $metadata['upgrade_message'] = $message;
                    $metadata['upgraded_at'] = now()->toIso8601String();

                    $wallet->update([
                        'ninepsb_tier' => (string) $newTier,
                        'ninepsb_metadata' => $metadata,
                    ]);

                    Log::info('9PSB Webhook: Wallet tier upgraded', [
                        'wallet_id' => $wallet->id,
                        'account_number' => $accountNumber,
                        'new_tier' => $newTier,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('9PSB Webhook: Error querying upgrade status', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->acknowledge();
    }

    /**
     * Standard 9PSB webhook acknowledgement response.
     * Per spec: {success: true, code: "00", status: "SUCCESS", message: "Acknowledged"}
     */
    private function acknowledge(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'code' => '00',
            'status' => 'SUCCESS',
            'message' => 'Acknowledged',
        ]);
    }

    /**
     * Validate Basic Auth header against configured credentials.
     */
    private function validateBasicAuth(Request $request): bool
    {
        $expectedUsername = config('services.ninepsb.webhook_username');
        $expectedPassword = config('services.ninepsb.webhook_password');

        // If no webhook credentials are configured, skip validation
        if (empty($expectedUsername) || empty($expectedPassword)) {
            Log::warning('9PSB Webhook: No Basic Auth credentials configured - skipping validation');
            return true;
        }

        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Basic ')) {
            return false;
        }

        $encoded = substr($authHeader, 6);
        $decoded = base64_decode($encoded);
        $parts = explode(':', $decoded, 2);

        if (count($parts) !== 2) {
            return false;
        }

        return $parts[0] === $expectedUsername && $parts[1] === $expectedPassword;
    }
}