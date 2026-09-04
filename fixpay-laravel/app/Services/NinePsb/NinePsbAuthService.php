<?php

namespace App\Services\NinePsb;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Handles authentication with 9PSB WAAS API.
 * Caches bearer tokens and auto-refreshes before expiry.
 */
class NinePsbAuthService
{
    private const CACHE_KEY = 'ninepsb:access_token';
    private const REFRESH_THRESHOLD_SECONDS = 120; // Refresh 2 minutes before expiry

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    /**
     * Get a valid bearer token, refreshing if necessary.
     */
    public function getToken(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached && isset($cached['token'], $cached['expires_at'])) {
            $remainingSeconds = $cached['expires_at'] - time();
            if ($remainingSeconds > self::REFRESH_THRESHOLD_SECONDS) {
                return $cached['token'];
            }
        }

        return $this->authenticate();
    }

    /**
     * Force a fresh authentication.
     */
    public function authenticate(): string
    {
        Log::info('9PSB: Authenticating with WAAS API', [
            'base_url' => $this->baseUrl,
            'username' => $this->username,
        ]);

        $response = Http::timeout(30)
            ->withoutVerifying()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post("{$this->baseUrl}/api/v1/authenticate", [
                'username' => $this->username,
                'password' => $this->password,
                'clientId' => $this->clientId,
                'clientSecret' => $this->clientSecret,
            ]);

        $body = $response->json();

        if ($response->failed() || empty($body['accessToken'])) {
            $errorMessage = $body['message'] ?? $body['error'] ?? 'Authentication failed';
            Log::error('9PSB: Authentication failed', ['response' => $body]);
            throw new \RuntimeException("9PSB Authentication failed: {$errorMessage}");
        }

        $accessToken = $body['accessToken'];
        $expiresIn = (int) ($body['expiresIn'] ?? 3600);

        // Cache token with expiry buffer
        Cache::put(self::CACHE_KEY, [
            'token' => $accessToken,
            'expires_at' => time() + $expiresIn,
            'refresh_token' => $body['refreshToken'] ?? null,
            'refresh_expires_in' => $body['refreshExpiresIn'] ?? null,
        ], $expiresIn);

        Log::info('9PSB: Authentication successful', [
            'expires_in' => $expiresIn,
        ]);

        return $accessToken;
    }

    /**
     * Invalidate cached token (e.g., on 401 response).
     */
    public function invalidateToken(): void
    {
        Cache::forget(self::CACHE_KEY);
        Log::info('9PSB: Token invalidated');
    }
}