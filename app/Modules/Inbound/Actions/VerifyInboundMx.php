<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Actions;

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Contracts\DnsUnavailable;
use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Services\DomainNormalizer;

/**
 * A BEJÖVŐ MX-rekord ellenőrzése — a fogadás BIZALMI HORGONYA (37. kritérium).
 *
 * Ez az egyetlen mechanizmus, ami eldönti, hogy egy domainre szabad-e levelet fogadnunk. A
 * `VerifiedInboundResolver` erre a mezőre épül, és azon áll vagy bukik az MX-oldal
 * nyílt-relay zára: ha bárki bármelyik domaint `verified`-re állíthatná, mindenki más
 * levelét is elfogadnánk.
 *
 * KÉT dolgot követelünk meg, és mindkettő szükséges:
 *
 *  1. az MX-rekord RÁNK mutat — enélkül nem is érkezne hozzánk levél, és a „fogadunk rá"
 *     állapot hazugság lenne;
 *  2. a domain a KÜLDÉSHEZ is hitelesített (`status = verified`) — a küldő-oldali
 *     hitelesítés bizonyítja, hogy az ügyfél tényleg birtokolja a domaint. Az MX-rekord
 *     önmagában csak azt mutatja, hogy valaki hozzánk irányítja a levelet; a TULAJDONLÁST a
 *     DKIM/SPF/DMARC hármas igazolja.
 *
 * A visszaesés ugyanúgy kezelt, mint az előrelépés: ha az ügyfél leveszi az MX-rekordot, a
 * fogadás megszűnik. Egy „egyszer verified, mindig verified" logika azt jelentené, hogy egy
 * eladott vagy elhagyott domain leveleit örökre elfogadjuk.
 */
final class VerifyInboundMx
{
    public function __construct(private readonly DnsResolver $resolver) {}

    public function __invoke(Domain $domain): string
    {
        $target = strtolower(trim((string) config('inbound.mx_target'), '. '));

        try {
            $records = $this->resolver->records((string) $domain->name, 'MX');
        } catch (DnsUnavailable) {
            // A „nem tudtuk megkérdezni" NEM „levették az MX-et". Ha itt `failed`-et
            // írnánk, egy resolver-kimaradás alatt MINDEN bejövő levelet elutasítanánk —
            // és a levelek nem sorban állnának, hanem visszapattannának.
            return (string) ($domain->inbound_mx_status ?? 'pending');
        }

        $found = false;

        foreach ($records as $record) {
            // Az MX-rekord `10 mx.ventmail.app.` alakú: a prioritás és a záró pont is
            // változhat, a HOSZT nem.
            $host = strtolower(trim((string) preg_replace('/^\s*\d+\s+/', '', $record), '. '));

            if ($host === $target || DomainNormalizer::canonical($host) === DomainNormalizer::canonical($target)) {
                $found = true;

                break;
            }
        }

        // A KÜLDÉSI hitelesítés is kell: az MX-rekord önmagában nem bizonyít tulajdonlást.
        $status = $found && $domain->status === 'verified' ? 'verified' : ($found ? 'pending' : 'failed');

        $domain->forceFill(['inbound_mx_status' => $status])->save();

        return $status;
    }
}
