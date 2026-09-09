<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Jobs;

use App\Modules\Webhooks\Services\WebhookSigner;
use App\Modules\Webhooks\Services\WebhookUrlGuard;
use Illuminate\Bus\Queueable;
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
 * AZ ÜGYELETI RIASZTÁS KÉZBESÍTÉSE (88. kritérium).
 *
 * ## Ugyanazok a zárak, mint a tenant-webhooknál — és most tényleg
 *
 * A korábbi változat docblockja azt állította, hogy „ugyanaz a minta, mint az A3
 * tenant-webhookjai". Mérve nem az volt: nem volt URL-jóváhagyás, nem volt
 * `allow_redirects => false`, és a `http://` is átment. A következmény nem elgépelést
 * igényel: egy legitim, publikus ügyeleti végpont DNS-eltérítése vagy kompromittálása
 * 302-vel a felhő metadata-endpointjára viheti a workerünket, a HMAC-aláírt payloadunkkal
 * a bodyban (mérve: `effectiveUri=http://127.0.0.1:9911/b`, HTTP 200).
 *
 * Ezért itt is: `WebhookUrlGuard::approve()` (csak `https`, publikus cím, IP-pinning),
 * `allow_redirects => false`, `verify => true`.
 *
 * ## Az ALÁÍRÁS MINDEN KÍSÉRLETNÉL ÚJRA készül
 *
 * A `WebhookSigner::TOLERANCE_SECONDS` 300 s. Ha a timestamp a dispatch pillanatában
 * fagyna be, akkor TORLÓDOTT SOR mellett — vagyis pontosan abban az üzemállapotban, amikor
 * riasztani kell — a késés átlépné a tűréshatárt, és a fogadó minden további kísérletet
 * elavult aláírásként utasítana el, dead-letterig. A titok ezért NEM utazik a
 * job-payloadban: a `handle()` a configból olvassa, és ott ír alá.
 */
final class DeliverOnCallAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * NÉGY kísérlet: az ügyeleti rendszer pillanatnyi hibája ne nyelje el a riasztást,
     * de a halott végpont felé se próbálkozzunk vég nélkül.
     */
    public $tries = 4;

    /** @var list<int> */
    public $backoff = [5, 30, 120];

    public function __construct(
        private readonly string $payload,
    ) {}

    public function handle(WebhookSigner $signer, WebhookUrlGuard $guard): void
    {
        $url = (string) config('superadmin.oncall.url');
        $secret = (string) config('superadmin.oncall.secret');

        if ($url === '' || $secret === '') {
            // A csatorna a dispatch óta kikapcsolt: HANGOS naplósor, nem csendes eldobás.
            Log::error('ÜGYELETI RIASZTÁS ELMARADT: a csatorna beállítása eltűnt', [
                'hint' => 'SUPERADMIN_ONCALL_URL és SUPERADMIN_ONCALL_SECRET',
            ]);

            return;
        }

        /*
         | SSRF-JÓVÁHAGYÁS a kérés ELŐTT, és a kérés a JÓVÁHAGYOTT IP-re megy.
         |
         | A tiltott cél nem múlik el magától (elgépelt vagy rosszindulatú env-érték), ezért
         | nincs értelme újrapróbálni: `error` naplósor és a kísérlet vége. A feloldási hiba
         | viszont ÁTMENETI — azt hagyjuk a retry-ra.
         */
        try {
            $target = $guard->approve($url);
        } catch (InvalidArgumentException $e) {
            Log::error('ÜGYELETI RIASZTÁS ELMARADT: a cél-URL nem publikus https cím', [
                'reason' => $e->getMessage(),
                'hint' => 'SUPERADMIN_ONCALL_URL',
            ]);

            return;
        }

        if (! extension_loaded('curl')) {
            // A pinning a cURL `CURLOPT_RESOLVE`-jára épül; nélküle a kliens ÚJRA feloldaná
            // a nevet, és a DNS-rebinding védelem nyom nélkül megszűnne. Fail-closed.
            Log::error('ÜGYELETI RIASZTÁS ELMARADT: az IP-pinninghez cURL szükséges');

            return;
        }

        $timestamp = now()->getTimestamp();

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            // UGYANAZ a fejléc-készlet, mint a tenant-webhookoknál: az ügyeleti rendszer
            // ugyanazzal a logikával ellenőrizheti, amit a tenantoknak dokumentálunk.
            'X-VentMail-Timestamp' => (string) $timestamp,
            'X-VentMail-Signature' => $signer->sign($secret, $this->payload, $timestamp),
            'X-VentMail-Alert' => 'oncall',
        ])
            ->withOptions([
                'verify' => true,
                // NINCS átirányítás-követés: egy 302 a belső hálózatra megkerülné a
                // fenti ellenőrzést.
                'allow_redirects' => false,
                'curl' => $target->curlOptions(),
            ])
            // RÖVID timeout: egy lassú ügyeleti rendszer nem foglalhatja a workert.
            ->timeout(10)
            ->connectTimeout(5)
            ->withBody($this->payload, 'application/json')
            ->post($url);

        if ($response->failed()) {
            // A KIVÉTEL a retry-t indítja. A `throw` szándékos: a job-keretrendszer
            // visszatartása az, ami az exponenciális újrapróbálást adja.
            throw new RuntimeException('Az ügyeleti riasztás elutasítva: HTTP '.$response->status());
        }
    }

    /**
     * A riasztás TARTALMA nem megy a naplóba: egy blocklist-riasztás a saját küldő IP-nket
     * és a zóna nevét tartalmazza, és a napló bizalmi szintje alacsonyabb, mint az
     * ügyeleti csatornáé.
     */
    public function failed(?Throwable $e): void
    {
        Log::error('ÜGYELETI RIASZTÁS NEM ÉRT CÉLBA', [
            'attempts' => $this->attempts(),
            'exception' => $e === null ? null : $e::class,
        ]);
    }
}
