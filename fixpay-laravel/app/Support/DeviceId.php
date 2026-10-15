<?php

namespace App\Support;

/**
 * Normalises the client-supplied device fingerprint.
 *
 * Clients — and some HTTP layers — can present the same device id under more
 * than one header spelling (`X-Device-ID` and `X-Device-Id`). Symfony then hands
 * Laravel a single value with the duplicates comma-joined (`"dev-01, dev-01"`).
 * Persisting that verbatim corrupts the cross-channel correlation key that TMS
 * buckets velocity/blocking by, so every consumer normalises through here.
 */
final class DeviceId
{
    /**
     * Read and normalise the device id from the current request.
     *
     * Prefers the canonical `X-Device-Id` spelling and falls back to
     * `X-Device-ID`; returns '' when the client sent nothing usable.
     */
    public static function fromRequest(): string
    {
        $request = request();
        $raw = $request->header('X-Device-Id') ?? $request->header('X-Device-ID') ?? '';

        return self::normalise(is_string($raw) ? $raw : '');
    }

    /**
     * Collapse a (possibly duplicate-joined) header value to distinct tokens.
     *
     * `"dev-01, dev-01"` -> `"dev-01"`; `"dev-01, dev-02"` -> `"dev-01,dev-02"`;
     * blank/whitespace -> `''`.
     */
    public static function normalise(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        $parts = array_map('trim', explode(',', $value));
        $parts = array_filter($parts, static fn (string $part): bool => $part !== '');

        return implode(',', array_values(array_unique($parts)));
    }
}
