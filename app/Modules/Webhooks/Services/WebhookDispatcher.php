<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Support\Collection;

/**
 * Egy esemény szétosztása a feliratkozott végpontokra.
 *
 * A kézbesítés SORON megy: egy lassú (vagy halott) ügyfél-végpont nem foghatja meg a
 * küldési utat. A `webhook_deliveries` sor MOST keletkezik, tehát a hívó akkor is látja a
 * függő kézbesítést, ha a worker épp áll.
 *
 * A VÉGPONTLISTA kérésen belül MEMOIZÁLT, és ez nem mikro-optimalizálás: egy 1000 címzettes
 * batch címzettenként külön eseményt ír, tehát a naiv változat 1000 (eseményenként új)
 * `select * from webhook_endpoints`-ot futtatna a `POST /v1/messages` kérésen BELÜL — és
 * mindezt kétszer, az `accepted` és a `delivered` miatt. A küldési út ettől lassul, nem a
 * webhookok kézbesítésétől.
 */
final class WebhookDispatcher
{
    /** Ennyi ideje van a soron következő workernek, mielőtt a söprő parancs is ránéz. */
    private const DISPATCH_GRACE_SECONDS = 120;

    /** @var array<int, Collection<int, WebhookEndpoint>> */
    private array $endpoints = [];

    public function dispatch(MessageEvent $event): void
    {
        $endpoints = $this->endpointsFor((int) $event->workspace_id);

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
                // A söprő parancs `next_attempt_at <= now()`-ra keres. `now()`-val az ÚJ sor
                // azonnal esedékes, tehát ha a queue-késés nagyobb, mint a söprés periódusa,
                // MINDEN webhook kétszer menne ki — a `delivered` ellenőrzés két párhuzamos
                // jobnál nem zár. A türelmi idő ezt a versenyt szünteti meg.
                'next_attempt_at' => now()->addSeconds(self::DISPATCH_GRACE_SECONDS),
            ]);

            DeliverWebhook::dispatch((int) $delivery->workspace_id, (string) $delivery->uuid);
        }
    }

    /**
     * A workspace aktív végpontjai — kérésenként EGYSZER lekérdezve.
     *
     * A kulcs a `workspace_id`, mert a worker hosszan él, és több tenant jobját futtatja
     * egymás után: közös cache-sel az egyik tenant végpontjaira mennének a másik eseményei.
     *
     * @return Collection<int, WebhookEndpoint>
     */
    private function endpointsFor(int $workspaceId): Collection
    {
        return $this->endpoints[$workspaceId] ??= WebhookEndpoint::query()->where('active', true)->get();
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
