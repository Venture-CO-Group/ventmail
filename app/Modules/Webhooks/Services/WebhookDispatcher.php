<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;

/**
 * Egy esemény szétosztása a feliratkozott végpontokra.
 *
 * A kézbesítés SORON megy: egy lassú (vagy halott) ügyfél-végpont nem foghatja meg a
 * küldési utat. A `webhook_deliveries` sor MOST keletkezik, tehát a hívó akkor is látja a
 * függő kézbesítést, ha a worker épp áll.
 */
final class WebhookDispatcher
{
    public function dispatch(MessageEvent $event): void
    {
        $endpoints = WebhookEndpoint::query()->where('active', true)->get();

        foreach ($endpoints as $endpoint) {
            if (! $endpoint->listensTo((string) $event->type)) {
                continue;
            }

            $delivery = WebhookDelivery::create([
                'webhook_endpoint_id' => $endpoint->getKey(),
                'event_type' => (string) $event->type,
                'message_uuid' => $event->message_uuid,
                'payload' => $this->payload($event),
                'status' => 'pending',
                'next_attempt_at' => now(),
            ]);

            DeliverWebhook::dispatch((int) $delivery->workspace_id, (string) $delivery->uuid);
        }
    }

    /**
     * A kimenő payload.
     *
     * TARTALOM NINCS BENNE: a kirenderelt levéltörzs magic-linket és tokent hordoz, és ez
     * a payload HARMADIK FÉLHEZ megy ki. A `v:*` változók viszont igen — azokat a hívó
     * maga küldte, és azokból azonosítja a saját rekordjait (Mailgun-paritás).
     *
     * @return array<string, mixed>
     */
    private function payload(MessageEvent $event): array
    {
        return [
            'event' => $event->type,
            'timestamp' => $event->occurred_at?->getTimestamp(),
            'message' => [
                'id' => $event->message_uuid,
                'recipient' => $event->recipient,
                'channel' => $event->channel,
                'tags' => $event->tags,
            ],
            'reason' => $event->reason,
            'variables' => $event->variables,
            'delivery' => [
                'code' => $event->payload['code'] ?? null,
                'enhanced_code' => $event->payload['enhanced_code'] ?? null,
                'classification' => $event->payload['classification'] ?? null,
            ],
        ];
    }
}
