<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Services;

use App\Modules\SuperAdmin\Jobs\DeliverOnCallAlert;
use Illuminate\Support\Facades\Log;

/**
 * ÜGYELETI RIASZTÁS (88. kritérium) — és SOHA nem e-mailben.
 *
 * ## Miért nem e-mail
 *
 * Mert a riasztás tárgya épp az, hogy a KÜLDÉSÜNK nem működik: a blocklist-riasztás azt
 * jelenti, hogy a saját küldő IP-nk listán van. E-mailben értesíteni az ügyeletet olyan,
 * mint a tűzriadót a leégett épületben kihirdetni. A `PHASE.md` ezt a csatlakozási pontot
 * nevesítette a `BlocklistStateChanged` eseménynél.
 *
 * ## Ugyanaz a minta, mint az A3 tenant-webhookjai
 *
 * HMAC-SHA256 aláírás + időbélyeg (replay-védelem) + exponenciális retry + dead-letter. Nem
 * írunk új mechanizmust: a `WebhookSigner` és a job-alapú retry már bizonyított (az
 * aláírást a job végzi, minden kísérletnél újra), és egy
 * második, párhuzamos implementáció elkerülhetetlenül elcsúszna tőle.
 *
 * A KÜLÖNBSÉG: ez PLATFORM-szintű, nem tenant-szintű. A cél URL és a titok configból jön
 * (env), nem adatbázisból — az ügyeleti csatorna a mi infrastruktúránk, és egy
 * adatbázis-kiesés esetén is működnie kell, amikor épp riasztani kell.
 */
final class OnCallAlerts
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function raise(string $type, string $summary, array $payload = []): bool
    {
        $url = (string) config('superadmin.oncall.url');
        $secret = (string) config('superadmin.oncall.secret');

        if ($url === '' || $secret === '') {
            /*
             | NINCS BEÁLLÍTVA: HANGOS naplósor, nem csendes eldobás.
             |
             | Egy nem konfigurált ügyeleti csatorna a legrosszabb állapot: a rendszer azt
             | hiszi, riasztott, az ügyelet pedig nem tud semmiről. A naplósor `error`
             | szintű, hogy a log-alapú monitorozás LÁSSA a riasztás elmaradását.
             */
            // A RIASZTÁS TARTALMA (`summary`) NEM megy a naplóba: blocklist-riasztásnál a
            // saját küldő IP-nket és a zóna nevét hordozza, és a napló bizalmi szintje
            // alacsonyabb, mint az ügyeleti csatornáé. A `DeliverOnCallAlert::failed()`
            // ugyanezt a szabályt követi — egy packeten belül nem lehet két ellentétes.
            Log::error('ÜGYELETI RIASZTÁS ELMARADT: nincs beállítva a csatorna', [
                'type' => $type,
                'hint' => 'SUPERADMIN_ONCALL_URL és SUPERADMIN_ONCALL_SECRET',
            ]);

            return false;
        }

        $body = [
            'type' => $type,
            'summary' => $summary,
            'payload' => $payload,
            // A KÜLDÉS IDEJE a payloadban is: az ügyeleti rendszer így akkor is tudja,
            // mikor keletkezett, ha a továbbítás késett.
            'raised_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
        ];

        /*
         | CSAK A PAYLOAD megy a jobba — se URL, se titok, se aláírás.
         |
         | Az aláírás időbélyege a dispatch pillanatában befagyott volna, a
         | `WebhookSigner::TOLERANCE_SECONDS` viszont 300 s: torlódott soron (vagyis
         | pontosan akkor, amikor riasztani kell) a fogadó minden kísérletet elavult
         | aláírásként utasított volna el. A `handle()` ezért minden kísérletnél ÚJRA ír
         | alá, a titkot a configból olvasva — így a titok a job-payloadban (Redisben,
         | `failed_jobs`-ban) sem jelenik meg.
         */
        DeliverOnCallAlert::dispatch(
            (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return true;
    }
}
