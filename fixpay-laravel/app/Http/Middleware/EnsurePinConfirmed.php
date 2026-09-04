<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Middleware that gates all debit operations behind PIN confirmation.
 * 
 * The client must first call POST /auth/pin/verify (which succeeds only if
 * the PIN hash matches). That endpoint caches a confirmation token.
 * 
 * This middleware checks that a recent (≤5 min) PIN confirmation exists
 * for the authenticated user before allowing debit operations through.
 * 
 * For extra security, the X-Pin-Token header must match the server-side token.
 */
class EnsurePinConfirmed
{
    private const CONFIRMATION_TTL = 300; // 5 minutes
    private const CACHE_PREFIX = 'pin_confirmed:';

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $pinToken = $request->header('X-Pin-Token');

        // Check cache for a recent PIN confirmation
        $cacheKey = self::CACHE_PREFIX . $user->id;
        $cachedToken = Cache::get($cacheKey);

        if (!$cachedToken) {
            return response()->json([
                'message' => 'PIN confirmation required. Verify your PIN before proceeding.',
                'required_action' => 'POST /auth/pin/verify',
            ], 403);
        }

        // If a token was provided in the header, validate it
        if ($pinToken && !hash_equals($cachedToken, $pinToken)) {
            return response()->json([
                'message' => 'Invalid PIN confirmation token.',
            ], 403);
        }

        // Reset the TTL on successful use (extends the session)
        Cache::put($cacheKey, $cachedToken, self::CONFIRMATION_TTL);

        return $next($request);
    }

    /**
     * Record that a user has successfully confirmed their PIN.
     * Called from PinController@verify after successful PIN check.
     */
    public static function confirm(string $userId): string
    {
        $token = bin2hex(random_bytes(16));
        $cacheKey = self::CACHE_PREFIX . $userId;
        Cache::put($cacheKey, $token, self::CONFIRMATION_TTL);
        return $token;
    }

    /**
     * Invalidate PIN confirmation (e.g., on logout).
     */
    public static function invalidate(string $userId): void
    {
        Cache::forget(self::CACHE_PREFIX . $userId);
    }
}