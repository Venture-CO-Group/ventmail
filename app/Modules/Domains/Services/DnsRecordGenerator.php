<?php

declare(strict_types=1);

namespace App\Modules\Domains\Services;

use App\Modules\Domains\Models\Domain;

/**
 * A hitelesítéshez szükséges DNS-rekordok (spec 4.).
 *
 * Egyetlen forrás: a wizard EZT jeleníti meg, és a DNS-check UGYANEZT ellenőrzi. Ha a
 * kettő szétcsúszna, az ügyfél a helyes rekordot másolná be, és a hitelesítés mégis
 * elbukna — ez a leggyakoribb ESP-támogatási hiba.
 */
final class DnsRecordGenerator
{
    /** @return array<string, array{type: string, name: string, value: string, purpose: string}> */
    public function for(Domain $domain): array
    {
        $host = $domain->name;

        return [
            'spf' => [
                'type' => 'TXT',
                'name' => $host,
                'value' => sprintf('v=spf1 include:%s ~all', config('domains.spf_include')),
                'purpose' => 'Feladó-hitelesítés: engedélyezi a ventMail küldő szervereit.',
            ],
            'dkim' => [
                'type' => 'TXT',
                'name' => sprintf('%s._domainkey.%s', $domain->dkim_selector, $host),
                'value' => sprintf('v=DKIM1; k=rsa; p=%s', $domain->dkim_public_key),
                'purpose' => 'Aláírás-hitelesítés: a levél tartalmának sértetlenségét igazolja.',
            ],
            'dmarc' => [
                'type' => 'TXT',
                'name' => '_dmarc.'.$host,
                'value' => sprintf(
                    'v=DMARC1; p=%s; rua=mailto:%s',
                    config('domains.dmarc_policy'),
                    config('domains.dmarc_rua')
                ),
                'purpose' => 'Házirend: mi történjen a hitelesítetlen levelekkel, és hova jöjjön a jelentés.',
            ],
            'tracking' => [
                'type' => 'CNAME',
                'name' => sprintf('%s.%s', config('domains.tracking_subdomain'), $host),
                'value' => config('domains.tracking_target'),
                'purpose' => 'Saját domainen futó klikk-átirányítás és nyitás-pixel.',
            ],
            // MTA-STS és TLS-RPT (A6 · 48. kritérium). Az előbbi a KÜLDŐNEK mondja meg,
            // hogy a mi domainünkre kötelező a TLS (a lefokozó támadás ellen), az utóbbi
            // pedig a fogadóktól kér jelentést a TLS-hibákról. A kettő együtt működik: az
            // egyik előír, a másik megmutatja, hogy betartják-e.
            'mta_sts' => [
                'type' => 'TXT',
                'name' => '_mta-sts.'.$host,
                // Az `id` a policy VÁLTOZÁSÁT jelzi a fogadóknak — nem a lekérdezés idejét.
                // `now()`-val minden wizard-megnyitás új értéket mutatott, tehát az ügyfél
                // rekordja a beírás pillanatában elavult, és a `verified` sosem jött volna el.
                'value' => 'v=STSv1; id='.($domain->updated_at ?? $domain->created_at ?? now())->format('YmdHis'),
                'purpose' => 'MTA-STS: a beérkező levelekhez kötelező TLS (opcionális, de erősen ajánlott).',
            ],
            'tls_rpt' => [
                'type' => 'TXT',
                'name' => '_smtp._tls.'.$host,
                'value' => sprintf('v=TLSRPTv1; rua=mailto:%s', config('domains.tls_rpt_rua', 'tls-reports@ventmail.app')),
                'purpose' => 'TLS-RPT: a fogadók ide jelentik, ha TLS-hiba volt.',
            ],
            // OPCIONÁLIS: csak akkor kell, ha a tenant BE is akar fogadni levelet (A4). A
            // küldéshez nem tartozik hozzá, és a `verified` státuszhoz sem — de enélkül a
            // bejövő route-ok sosem kapnak levelet.
            'inbound_mx' => [
                'type' => 'MX',
                'name' => $host,
                'value' => sprintf('10 %s', config('inbound.mx_target')),
                'purpose' => 'Bejövő levelek fogadása (opcionális — csak ha inbound routingot használsz).',
            ],
        ];
    }
}
