<?php

namespace App\Services\Security;

use App\Models\IdempotentRequest;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Enforces security guardrails for all 9PSB transactions:
 * - Idempotency (prevent duplicate processing)
 * - Anti-replay / MITM protection (nonce + request signing)
 * - Device binding (one wallet per device)
 * - Location tagging (every transaction carries coordinates)
 */
class TransactionGuardService
{
    private const NONCE_TTL_SECONDS = 300;    // Nonce valid for 5 minutes
    private const IDEMPOTENCY_TTL_SECONDS = 86400; // Idempotency keys valid for 24 hours
    private const MAX_REQUEST_AGE_SECONDS = 60; // Request timestamp must be within 60s

    /**
     * Validate and enforce all security guardrails before processing a transaction.
     *
     * @throws \RuntimeException if any guardrail fails
     */
    public function enforce(Wallet $wallet, Request $request): void
    {
        // 1. Device binding check
        $this->enforceDeviceBinding($wallet, $request);

        // 2. Location check
        $this->enforceLocation($request);

        // 3. Anti-replay: validate request timestamp freshness
        $this->enforceRequestTimestamp($request);

        // 4. Anti-replay: validate nonce
        $this->enforceNonce($request);

        // 5. Request signing (MITM protection)
        $this->enforceRequestSignature($request);
    }

    /**
     * Enforce idempotency on transaction processing.
     * If the idempotency key has been seen before, return the cached result.
     *
     * @return array|null Cached result if duplicate, null if new request
     */
    public function checkIdempotency(string $idempotencyKey): ?array
    {
        $cached = Cache::get("idempotency:{$idempotencyKey}");

        if ($cached) {
            return [
                'duplicate' => true,
                'original_response' => $cached,
                'message' => 'Duplicate request. Returning original response.',
            ];
        }

        // Check database for persisted idempotency (only COMPLETED rows are
        // duplicates — the IdempotencyMiddleware pre-creates a PROCESSING row for
        // the current in-flight request, which must NOT be treated as a replay).
        $existing = IdempotentRequest::where('idempotency_key', $idempotencyKey)
            ->where('status', 'COMPLETED')
            ->first();
        if ($existing) {
            return [
                'duplicate' => true,
                'original_response' => $existing->response_payload,
                'message' => 'Duplicate request detected.',
            ];
        }

        return null;
    }

    /**
     * Record an idempotency key after successful processing.
     */
    public function recordIdempotency(string $idempotencyKey, array $response): void
    {
        Cache::put("idempotency:{$idempotencyKey}", $response, self::IDEMPOTENCY_TTL_SECONDS);

        // updateOrCreate: the IdempotencyMiddleware pre-creates a PROCESSING row for
        // the in-flight request, so this must UPDATE that row rather than INSERT a
        // duplicate (idempotency_key is unique).
        IdempotentRequest::updateOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'response_payload' => $response,
                'response_body' => $response,
                'response_code' => 200,
                'status' => 'COMPLETED',
                'expires_at' => now()->addSeconds(self::IDEMPOTENCY_TTL_SECONDS),
            ]
        );
    }

    /**
     * Bind a wallet to a specific device. Called once on wallet creation.
     */
    public function bindDevice(Wallet $wallet, string $deviceId): void
    {
        $wallet->update([
            'device_id' => hash('sha256', $deviceId),
            'device_bound_at' => now(),
        ]);
    }

    /**
     * Record location for a wallet.
     */
    public function recordLocation(Wallet $wallet, float $lat, float $lng): void
    {
        $wallet->update([
            'last_location_lat' => $lat,
            'last_location_lng' => $lng,
            'last_location_at' => now(),
        ]);
    }

    /**
     * Enforce tier-based transaction limits.
     * 
     * Tier 1 (BVN only):       ₦50,000/day,  ₦300,000/month
     * Tier 2 (ID verified):    ₦200,000/day, ₦1,000,000/month
     * Tier 3 (full KYC):       ₦1,000,000/day, ₦5,000,000/month
     *
     * @throws \RuntimeException if limit is exceeded
     */
    public function enforceTierLimits(Wallet $wallet, int $amountKobo): void
    {
        $tier = (int) ($wallet->ninepsb_tier ?? 1);

        $limits = match ($tier) {
            1 => ['daily' => 5_000_000, 'monthly' => 30_000_000],
            2 => ['daily' => 20_000_000, 'monthly' => 100_000_000],
            3 => ['daily' => 100_000_000, 'monthly' => 500_000_000],
            default => ['daily' => 5_000_000, 'monthly' => 30_000_000],
        };

        // Check daily limit
        $todayTotal = \App\Models\LedgerEntry::where('wallet_id', $wallet->id)
            ->where('entry_type', 'DEBIT')
            ->whereDate('created_at', today())
            ->sum('amount_kobo');

        if (($todayTotal + $amountKobo) > $limits['daily']) {
            $limitNaira = number_format($limits['daily'] / 100);
            throw new \RuntimeException(
                "Tier {$tier} daily limit of ₦{$limitNaira} exceeded.",
                403
            );
        }

        // Check monthly limit
        $monthTotal = \App\Models\LedgerEntry::where('wallet_id', $wallet->id)
            ->where('entry_type', 'DEBIT')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('amount_kobo');

        if (($monthTotal + $amountKobo) > $limits['monthly']) {
            $limitNaira = number_format($limits['monthly'] / 100);
            throw new \RuntimeException(
                "Tier {$tier} monthly limit of ₦{$limitNaira} exceeded.",
                403
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // PRIVATE: Individual guardrail checks
    // ─────────────────────────────────────────────────────────────────

    private function enforceDeviceBinding(Wallet $wallet, Request $request): void
    {
        // Only enforce if wallet has been bound to a device
        if (!$wallet->device_id) {
            return; // Wallet not yet bound (first transaction)
        }

        $clientDeviceId = $request->header('X-Device-ID');

        if (!$clientDeviceId) {
            throw new \RuntimeException('Device ID required. Wallet is bound to a specific device.', 403);
        }

        $hashedClientDevice = hash('sha256', $clientDeviceId);

        if (!hash_equals($wallet->device_id, $hashedClientDevice)) {
            throw new \RuntimeException(
                'This wallet is bound to a different device. Device change requires verification.',
                403
            );
        }
    }

    private function enforceLocation(Request $request): void
    {
        $lat = $request->header('X-Location-Lat');
        $lng = $request->header('X-Location-Lng');

        if (!$lat || !$lng) {
            throw new \RuntimeException('Location is required for all transactions. Please enable location services.', 403);
        }

        $latFloat = (float) $lat;
        $lngFloat = (float) $lng;

        // Basic bounds check for Nigerian coordinates
        if ($latFloat < 4.0 || $latFloat > 14.0 || $lngFloat < 2.5 || $lngFloat > 15.0) {
            throw new \RuntimeException('Invalid location coordinates. Must be within Nigeria.', 400);
        }
    }

    private function enforceRequestTimestamp(Request $request): void
    {
        $timestamp = $request->header('X-Request-Timestamp');

        if (!$timestamp) {
            throw new \RuntimeException('Request timestamp required.', 400);
        }

        $requestTime = (int) $timestamp;
        $now = time();

        if (abs($now - $requestTime) > self::MAX_REQUEST_AGE_SECONDS) {
            throw new \RuntimeException(
                'Request timestamp outside acceptable range. Ensure device clock is correct.',
                400
            );
        }
    }

    private function enforceNonce(Request $request): void
    {
        $nonce = $request->header('X-Request-Nonce');

        if (!$nonce) {
            throw new \RuntimeException('Request nonce required.', 400);
        }

        if (strlen($nonce) < 16) {
            throw new \RuntimeException('Invalid nonce format.', 400);
        }

        $cacheKey = "nonce:{$nonce}";

        if (Cache::has($cacheKey)) {
            throw new \RuntimeException('Request replay detected. Nonce already used.', 400);
        }

        Cache::put($cacheKey, true, self::NONCE_TTL_SECONDS);
    }

    private function enforceRequestSignature(Request $request): void
    {
        $signature = $request->header('X-Request-Signature');

        if (!$signature) {
            // Signature is optional but recommended; log warning if absent
            \Illuminate\Support\Facades\Log::warning('TransactionGuard: Missing request signature', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);
            return;
        }

        // Signature format: HMAC-SHA256(payload, secret)
        // Client computes: HMAC-SHA256(JSON.stringify(body) + nonce + timestamp, device_secret)
        // This validates the request body hasn't been tampered with in transit
        $nonce = $request->header('X-Request-Nonce', '');
        $timestamp = $request->header('X-Request-Timestamp', '');
        $payload = json_encode($request->all());

        // For now, use a shared secret derivable from the user's auth token
        // In production, this would use a per-device secret established during device binding
        $expectedSignature = hash_hmac(
            'sha256',
            $payload . $nonce . $timestamp,
            substr($request->bearerToken() ?? '', 0, 32)
        );

        if (!hash_equals($expectedSignature, $signature)) {
            throw new \RuntimeException('Request signature verification failed. Possible MITM attack.', 403);
        }
    }
}