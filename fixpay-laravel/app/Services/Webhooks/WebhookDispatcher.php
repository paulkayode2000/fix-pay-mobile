<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns a domain event into one signed delivery job per subscribed endpoint.
 *
 * This is the piece that is currently missing in fixpay-laravel: WebhookEndpoint
 * registration exists, but no code emits events. Call this from the money flows.
 */
class WebhookDispatcher
{
    /**
     * @param  string  $topic  e.g. "transfer.completed", "bill.paid"
     * @param  array   $data   payload; MUST include enough to reconcile on the receiver
     * @param  string|null $tenantId  UUID of the tenant (null = all endpoints)
     */
    public function dispatch(string $topic, array $data, ?string $tenantId = null): void
    {
        $query = WebhookEndpoint::query()->where('active', true);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $endpoints = $query->get()->filter(function (WebhookEndpoint $endpoint) use ($topic) {
            $events = $endpoint->events ?? [];

            // Empty event list = subscribe to everything.
            return empty($events) || in_array($topic, $events, true);
        });

        foreach ($endpoints as $endpoint) {
            $envelope = [
                'event_id' => (string) Str::uuid(),
                'event' => $topic,
                'tenant_id' => (string) $endpoint->tenant_id,
                'timestamp' => now()->toIso8601String(),
                'data' => $data,
            ];

            DeliverWebhookJob::dispatch($endpoint->id, $envelope)->onQueue('webhooks');

            Log::info('webhook.dispatched', [
                'topic' => $topic,
                'endpoint_id' => $endpoint->id,
                'event_id' => $envelope['event_id'],
            ]);
        }
    }
}
