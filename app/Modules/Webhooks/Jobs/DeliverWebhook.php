<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Jobs;

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Services\WebhookSigner;
use App\Modules\Webhooks\Services\WebhookUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
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
    ) {
        // A job csak a COMMIT után indulhat.
        //
        // A `webhook_deliveries` sort a `MessageEvent::created` hookja írja, ami tranzakción
        // belül fut (`SendMessage` `DB::transaction`-je). A connectionök `after_commit`-je
        // `false`, tehát enélkül a worker a commit ELŐTT keresné a sort, nem találná, és
        // csendben visszatérne — a kézbesítés pedig ÖRÖKRE `pending` maradna.
        $this->afterCommit();
    }

    public function handle(WorkspaceContext $context, WebhookSigner $signer): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            $delivery = WebhookDelivery::query()->where('uuid', $this->deliveryUuid)->first();

            if ($delivery === null || $delivery->status === 'delivered') {
                return;
            }

            $this->attempt($delivery, $signer);
        } finally {
            // A KORÁBBI kontextust visszaállítjuk, nem egyszerűen elengedjük.
            //
            // Hosszan élő workernél a bent felejtett kontextus a következő job adatait idegen
            // tenantnak könyvelné el — ezért kell elengedni. `sync` queue-nál viszont ez a kód a
            // HÍVÓ kérésén belül fut: ott a vak `forget()` a hívó kontextusát törölné a küldés
            // közepén, és a következő lekérdezése „nincs workspace" hibára futna. A kettőt együtt
            // csak a visszaállítás oldja meg.
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    private function attempt(WebhookDelivery $delivery, WebhookSigner $signer): void
    {
        $endpoint = $delivery->endpoint;

        if ($endpoint === null || ! $endpoint->active) {
            return;
        }

        // SSRF: a cél-URL-t az ÜGYFÉL adja meg, a kérést MI indítjuk a saját hálózatunkból.
        // A guard nemcsak ENGEDÉLYT ad, hanem a JÓVÁHAGYOTT CÍMET is — a kérés arra megy
        // (`CURLOPT_RESOLVE`), különben a HTTP-kliens ÚJRA feloldaná a nevet, és a
        // DNS-rebinding pontosan a két feloldás közé férne be.
        try {
            $target = app(WebhookUrlGuard::class)->approve((string) $endpoint->url);
        } catch (InvalidArgumentException) {
            // Tiltott cél: ez nem múlik el magától, tehát nincs értelme újrapróbálni.
            $this->deadLetter($delivery, 'A cél-URL nem publikus címre mutat (SSRF-védelem).');

            return;
        } catch (RuntimeException) {
            // A hosztnév MOST nem oldható fel. Ez ÁTMENETI hiba — DNS-kiesésért nem
            // dobjuk el az ügyfél eseményét.
            $this->reschedule($delivery, 'A cél hosztneve nem oldható fel.');

            return;
        }

        // A titok MEGFEJTÉSE a `try` ELŐTT: ha az `APP_KEY` elmozdul, az KONFIGURÁCIÓS
        // hiba, nem az ügyfél végpontjának a hibája. Ha a lenti általános `catch`-be
        // esne, minden ügyfél minden webhookja hatszor „hálózati hibát" mutatna, aztán
        // dead-letter lenne — egy elgépelt URL-től megkülönböztethetetlenül.
        try {
            $secret = $endpoint->signingSecret();
        } catch (DecryptException) {
            Log::error('A webhook-titok nem fejthető meg — APP_KEY-eltérés?', [
                'workspace_id' => $this->workspaceId,
                'endpoint_uuid' => $endpoint->uuid,
            ]);

            $this->reschedule($delivery, 'A végpont titka nem fejthető meg (konfigurációs hiba).', increment: false);

            return;
        }

        $payload = (string) json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();

        $attempt = (int) $delivery->attempt + 1;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-VentMail-Timestamp' => (string) $timestamp,
                'X-VentMail-Signature' => $signer->sign($secret, $payload, $timestamp),
                'X-VentMail-Event' => $delivery->event_type,
                'X-VentMail-Delivery' => $delivery->uuid,
            ])
                // NINCS átirányítás-követés: egy 302 a belső hálózatra megkerülné a
                // fenti ellenőrzést.
                ->withOptions([
                    'verify' => true,
                    'allow_redirects' => false,
                    // A kapcsolat az ELLENŐRZÖTT címre épül; a `Host` fejléc és a TLS-SNI
                    // marad a hosztnév, tehát a tanúsítvány a valódi néven ellenőrződik.
                    'curl' => $target->curlOptions(),
                ])
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

    private function deadLetter(WebhookDelivery $delivery, string $why): void
    {
        $delivery->forceFill([
            'status' => 'dead_letter',
            'attempt' => (int) $delivery->attempt + 1,
            'response_excerpt' => $why,
            'next_attempt_at' => null,
            'dead_lettered_at' => now(),
        ])->save();
    }

    /**
     * Átmeneti akadály: a sor MARAD, és később újra sorra kerül.
     *
     * A konfigurációs hibánál (`increment: false`) a számlálót sem léptetjük: az `APP_KEY`
     * javítása után az ügyfél nem veszíthet kísérleteket egy MI hibánk miatt.
     */
    private function reschedule(WebhookDelivery $delivery, string $why, bool $increment = true): void
    {
        $attempt = (int) $delivery->attempt + ($increment ? 1 : 0);

        $delivery->forceFill([
            'status' => 'failed',
            'attempt' => $attempt,
            'response_excerpt' => $why,
            'next_attempt_at' => now()->addSeconds($this->backoffSeconds(max(1, $attempt))),
        ])->save();
    }

    /** 1 perc → 2 → 4 → 8 → 16 perc; a hatodik után dead-letter. */
    private function backoffSeconds(int $attempt): int
    {
        return 60 * (2 ** max(0, $attempt - 1));
    }
}
