<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Jobs;

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Services\WebhookSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Egy webhook-kézbesítés — aláírva, újrapróbálással, dead-letterrel.
 *
 * A visszalépés exponenciális: a halott végpontot nem érdemes másodpercenként hívni, a
 * pillanatnyi hibát viszont igen. `MAX_ATTEMPTS` után a sor DEAD-LETTER lesz — nem tűnik
 * el, hanem láthatóvá válik, és az UI-ból újrajátszható.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A queue-worker nem próbálkozik: az újrapróbálást MI ütemezzük, láthatóan. */
    public int $tries = 1;

    private const MAX_ATTEMPTS = 6;

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $deliveryUuid,
    ) {}

    public function handle(WorkspaceContext $context, WebhookSigner $signer): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $context->set($workspace);

        try {
            $delivery = WebhookDelivery::query()->where('uuid', $this->deliveryUuid)->first();

            if ($delivery === null || $delivery->status === 'delivered') {
                return;
            }

            $this->attempt($delivery, $signer);
        } finally {
            // Hosszan élő worker: a bent felejtett kontextus a KÖVETKEZŐ job adatait
            // idegen tenantnak könyvelné el.
            $context->forget();
        }
    }

    private function attempt(WebhookDelivery $delivery, WebhookSigner $signer): void
    {
        $endpoint = $delivery->endpoint;

        if ($endpoint === null || ! $endpoint->active) {
            return;
        }

        $payload = (string) json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();

        $attempt = (int) $delivery->attempt + 1;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-VentMail-Timestamp' => (string) $timestamp,
                'X-VentMail-Signature' => $signer->sign($endpoint->signingSecret(), $payload, $timestamp),
                'X-VentMail-Event' => $delivery->event_type,
                'X-VentMail-Delivery' => $delivery->uuid,
            ])
                ->withOptions(['verify' => true])
                ->timeout(10)
                ->connectTimeout(5)
                ->withBody($payload, 'application/json')
                ->post($endpoint->url);

            $successful = $response->successful();
            $code = $response->status();
            // A válasz ELEJE: egy hibás végpont teljes HTML-hibaoldala nem tartozik a
            // naplóba (méret és tartalom miatt sem).
            $excerpt = mb_substr((string) $response->body(), 0, 500);
        } catch (Throwable $e) {
            $successful = false;
            $code = null;
            $excerpt = 'Hálózati hiba: '.$e::class;
        }

        if ($successful) {
            $delivery->forceFill([
                'status' => 'delivered',
                'attempt' => $attempt,
                'response_code' => $code,
                'response_excerpt' => $excerpt,
                'delivered_at' => now(),
                'next_attempt_at' => null,
                'dead_lettered_at' => null,
            ])->save();

            $endpoint->forceFill(['last_success_at' => now()])->save();

            return;
        }

        $dead = $attempt >= self::MAX_ATTEMPTS;

        $delivery->forceFill([
            'status' => $dead ? 'dead_letter' : 'failed',
            'attempt' => $attempt,
            'response_code' => $code,
            'response_excerpt' => $excerpt,
            'next_attempt_at' => $dead ? null : now()->addSeconds($this->backoffSeconds($attempt)),
            'dead_lettered_at' => $dead ? now() : null,
        ])->save();

        $endpoint->forceFill(['last_failure_at' => now()])->save();
    }

    /** 1 perc → 2 → 4 → 8 → 16 perc; a hatodik után dead-letter. */
    private function backoffSeconds(int $attempt): int
    {
        return 60 * (2 ** max(0, $attempt - 1));
    }
}
