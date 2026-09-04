<?php

namespace App\Services\Tms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the TMS antifraud-service (FastAPI).
 *
 * Scores a single transaction via AML/PEP watchlist screening + ML anomaly
 * detection (Isolation Forest + LOF ensemble). Auth via X-API-Key header
 * (empty key = dev mode on the service).
 */
class AntifraudClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout,
        private readonly RiskRulesCache $rulesCache,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('services.antifraud.enabled', false);
    }

    /** POST /v1/transactions/score */
    public function scoreTransaction(array $payload): array
    {
        if (! $this->enabled()) {
            return ['status' => 'disabled', 'message' => 'Antifraud service is not enabled.'];
        }

        $meta = $payload['metadata'] ?? [];
        $source = (string) ($meta['source'] ?? '');
        $businessId = (string) ($meta['business_id'] ?? '');
        $rules = $this->rulesCache->get($source, $businessId);
        $version = (string) ($rules['version'] ?? '');

        try {
            $http = Http::timeout($this->timeout)
                ->acceptJson()
                ->withHeaders(['X-Rules-Version' => $version]);

            if ($this->apiKey !== '') {
                $http->withHeaders(['X-API-Key' => $this->apiKey]);
            }

            $response = $http->post($this->baseUrl.'/v1/transactions/score', $payload);

            // Rules version stale: swap the inline ruleset and retry once.
            if ($response->status() === 412) {
                $detail = $response->json('detail') ?? [];
                if (isset($detail['rules']) && is_array($detail['rules'])) {
                    $this->rulesCache->put($source, $businessId, $detail['rules']);
                    $version = (string) ($detail['current_version'] ?? $version);
                    Log::warning('AntifraudClient: stale rules, retrying', [
                        'old_version' => $rules['version'] ?? null,
                        'current_version' => $version,
                    ]);
                    $response = $http
                        ->withHeaders(['X-Rules-Version' => $version])
                        ->post($this->baseUrl.'/v1/transactions/score', $payload);
                }
            }

            $body = $response->json() ?? [];

            if ($response->successful()) {
                return $body;
            }

            Log::warning('AntifraudClient: score failed', [
                'status' => $response->status(),
                'body'   => $body,
            ]);

            return ['status' => 'error', 'message' => "Antifraud returned HTTP {$response->status()}"];
        } catch (\Throwable $e) {
            Log::error('AntifraudClient: score exception', ['error' => $e->getMessage()]);

            return ['status' => 'unavailable', 'message' => 'Antifraud service is not reachable'];
        }
    }

    /** GET /v1/health */
    public function healthy(): bool
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl.'/v1/health');

            return $response->successful() && ($response->json()['status'] ?? '') === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }
}
