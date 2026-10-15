<?php

namespace App\Jobs;

use App\Models\WebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Signs and delivers a single webhook envelope, with exponential backoff.
 *
 * Signature contract (verified by FixChat in apps/api/src/modules/fixpay/fixpay-signature.ts):
 *   header:  X-Fixpay-Signature: sha256=<hex(hmac_sha256(rawBody, signing_secret))>
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 6;

    /** seconds between attempts */
    public array $backoff = [10, 30, 120, 600, 1800, 3600];

    public function __construct(
        // WebhookEndpoint uses HasUuids, so ids are UUID strings — not ints.
        public string $endpointId,
        public array $envelope,
    ) {
    }

    public function handle(): void
    {
        /** @var WebhookEndpoint|null $endpoint */
        $endpoint = WebhookEndpoint::find($this->endpointId);

        if (! $endpoint || ! $endpoint->active) {
            return; // endpoint removed/disabled — nothing to deliver to
        }

        $body = json_encode($this->envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = 'sha256='.hash_hmac('sha256', $body, $endpoint->signing_secret);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Fixpay-Signature' => $signature,
            'X-Fixpay-Event' => $this->envelope['event'],
            'X-Fixpay-Event-Id' => $this->envelope['event_id'],
        ])
            ->timeout(15)
            ->withBody($body, 'application/json')
            ->post($endpoint->url);

        if ($response->failed()) {
            throw new RuntimeException(
                sprintf('Webhook delivery failed [%s] to endpoint %d', $response->status(), $endpoint->id)
            );
        }

        $endpoint->forceFill([
            'failure_count' => 0,
            'last_triggered_at' => now(),
        ])->save();
    }

    public function failed(\Throwable $e): void
    {
        WebhookEndpoint::whereKey($this->endpointId)->increment('failure_count');
    }
}
