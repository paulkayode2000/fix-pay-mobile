<?php

namespace App\Services\Tms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * In-memory cache of the TMS-published risk ruleset, keyed by
 * (source, business_id). The requesting entity (fixpay-mobile) fetches
 * GET /v1/rules and keeps the rules in memory so it can decide BEFORE
 * dispatching whether a transaction needs AML / fraud / velocity — the version
 * is echoed back on every score call via the X-Rules-Version header.
 */
class RiskRulesCache
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $ttl,
    ) {}

    /** Return the effective ruleset for a scope; cached for the TTL. */
    public function get(string $source, string $businessId): array
    {
        $key = $this->cacheKey($source, $businessId);

        return Cache::remember($key, $this->ttl, function () use ($source, $businessId) {
            return $this->fetch($source, $businessId);
        });
    }

    /** Store a fresh ruleset (e.g. inline rules from a 412 response). */
    public function put(string $source, string $businessId, array $rules): void
    {
        Cache::put($this->cacheKey($source, $businessId), $rules, $this->ttl);
    }

    private function fetch(string $source, string $businessId): array
    {
        try {
            $http = Http::timeout(5)->acceptJson();
            if ($this->apiKey !== '') {
                $http->withHeaders(['X-API-Key' => $this->apiKey]);
            }
            $response = $http->get($this->baseUrl.'/v1/rules', [
                'source' => $source,
                'business_id' => $businessId,
            ]);
            if ($response->successful()) {
                $rules = $response->json();

                return is_array($rules) && isset($rules['version']) ? $rules : self::defaults();
            }
            Log::warning('RiskRulesCache: fetch failed', [
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('RiskRulesCache: fetch exception', ['error' => $e->getMessage()]);
        }

        return self::defaults();
    }

    /** Seed defaults used until the first successful fetch (mirror TMS defaults). */
    public static function defaults(): array
    {
        return [
            'version' => '0.0',
            'velocity' => ['enabled' => true],
            'aml' => [
                'enabled' => true,
                'amount_threshold' => 1_000_000.0,
                'screen_wallet_open' => true,
            ],
            'fraud' => [
                'enabled' => true,
                'amount_threshold' => 100_000.0,
            ],
            'block_on_flag' => true,
        ];
    }

    private function cacheKey(string $source, string $businessId): string
    {
        return 'antifraud:rules:'.($source ?: '?').':'.($businessId ?: '?');
    }
}
