<?php

namespace App\Services\Tms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the TMS aml-system (Laravel) machine-to-machine API.
 *
 * Screens customers against sanctions/PEP/adverse-media/fraud lists — either
 * synchronously or asynchronously (TMS delivers results to our webhook) — and
 * can ingest transaction events into the TMS rule engine.
 *
 * Degrades gracefully: when disabled or unreachable it returns a structured
 * { status: disabled|unavailable, ... } response and never throws into the
 * payment path.
 */
class AmlClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiToken,
        private readonly int $timeout,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('services.tms.enabled', false);
    }

    /** POST /api/v1/screen — async (webhook) mode, returns call_ref + status. */
    public function screenAsync(array $customer): array
    {
        return $this->post('/api/v1/screen', $customer + ['screening_mode' => 'async']);
    }

    /** POST /api/v1/screen — synchronous mode, returns results inline. */
    public function screenSync(array $customer): array
    {
        return $this->post('/api/v1/screen', $customer + ['screening_mode' => 'sync']);
    }

    /** GET /api/v1/calls/{callRef} — poll a previously submitted screening. */
    public function poll(string $callRef): array
    {
        return $this->get('/api/v1/calls/'.$callRef);
    }

    /** POST /api/v1/ingest — push a transaction event into the TMS rule engine. */
    public function ingest(array $event): array
    {
        return $this->post('/api/v1/ingest', $event);
    }

    // ─────────────────────────────────────────────────────────────

    private function client()
    {
        return Http::timeout($this->timeout)
            ->withToken($this->apiToken)
            ->acceptJson();
    }

    private function post(string $path, array $payload): array
    {
        if (! $this->enabled()) {
            return ['status' => 'disabled', 'message' => 'TMS is not enabled.'];
        }

        try {
            $response = $this->client()->post($this->baseUrl.$path, $payload);
            $body = $response->json() ?? [];

            if ($response->successful() || $response->status() === 202 || $response->status() === 409) {
                return $body;
            }

            Log::warning('AmlClient: request failed', [
                'path'   => $path,
                'status' => $response->status(),
                'body'   => $body,
            ]);

            return ['status' => 'error', 'message' => "TMS returned HTTP {$response->status()}"];
        } catch (\Throwable $e) {
            Log::error('AmlClient: request exception', ['path' => $path, 'error' => $e->getMessage()]);

            return ['status' => 'unavailable', 'message' => 'TMS is not reachable'];
        }
    }

    private function get(string $path): array
    {
        if (! $this->enabled()) {
            return ['status' => 'disabled', 'message' => 'TMS is not enabled.'];
        }

        try {
            $response = $this->client()->get($this->baseUrl.$path);
            $body = $response->json() ?? [];

            if ($response->successful()) {
                return $body;
            }

            Log::warning('AmlClient: GET failed', [
                'path'   => $path,
                'status' => $response->status(),
                'body'   => $body,
            ]);

            return ['status' => 'error', 'message' => "TMS returned HTTP {$response->status()}"];
        } catch (\Throwable $e) {
            Log::error('AmlClient: GET exception', ['path' => $path, 'error' => $e->getMessage()]);

            return ['status' => 'unavailable', 'message' => 'TMS is not reachable'];
        }
    }
}
