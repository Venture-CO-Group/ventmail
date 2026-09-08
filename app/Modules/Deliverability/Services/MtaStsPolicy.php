<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Services;

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Contracts\DnsUnavailable;
use App\Modules\Domains\Models\Domain;

/**
 * MTA-STS és TLS-RPT (48. kritérium).
 *
 * Az MTA-STS azt mondja meg a KÜLDŐNEK, hogy a mi domainünkre KÖTELEZŐ a TLS — és melyik
 * MX-eken. Enélkül a klasszikus lefokozó (downgrade) támadás él: a támadó a
 * `STARTTLS`-hirdetést kitörli a válaszból, és a levél titkosítatlanul megy át.
 *
 * A TLS-RPT pedig azt kéri, hogy a fogadók JELENTSÉK, ha nálunk TLS-hiba volt. A kettő
 * együtt működik: az egyik előír, a másik megmutatja, hogy betartják-e.
 *
 * A `testing` mód szándékos alapérték: `enforce`-ban egy elrontott MX-lista MINDEN bejövő
 * levelet blokkolna, és a hibát csak akkor vennénk észre, amikor már senki nem ír nekünk.
 */
final class MtaStsPolicy
{
    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * A `/.well-known/mta-sts.txt` tartalma.
     *
     * A szabvány szerint ez egy sima szöveges fájl, HTTPS-en, az `mta-sts.<domain>` hoszton —
     * és épp az a lényege, hogy HTTPS-en jön: a tanúsítvány igazolja, hogy tényleg a domain
     * tulajdonosa írta, nem egy útközbeni támadó.
     */
    public function policyFor(Domain $domain): string
    {
        $lines = [
            'version: STSv1',
            'mode: '.config('deliverability.mta_sts.mode'),
        ];

        foreach ($this->mailExchangers($domain) as $mx) {
            $lines[] = 'mx: '.$mx;
        }

        $lines[] = 'max_age: '.config('deliverability.mta_sts.max_age');

        return implode("\n", $lines)."\n";
    }

    /**
     * Érvényes-e a domain MTA-STS beállítása?
     *
     * Két dolog kell: a `_mta-sts.<domain>` TXT rekord (ez jelzi a policy LÉTEZÉSÉT és a
     * verzióját), és maga a policy-fájl. A TXT `id` mezője változik, valahányszor a policy
     * változik — enélkül a fogadók a régi, cache-elt változatot használnák.
     */
    public function status(Domain $domain): string
    {
        try {
            $records = $this->dns->records('_mta-sts.'.$domain->name, 'TXT');
        } catch (DnsUnavailable) {
            // A „nem tudtuk megkérdezni" nem „nincs beállítva".
            return 'unknown';
        }

        foreach ($records as $record) {
            if (str_contains((string) $record, 'v=STSv1')) {
                return 'verified';
            }
        }

        return 'failed';
    }

    /** A TLS-RPT rekord: hova jöjjenek a TLS-hibajelentések. */
    public function tlsRptRecord(Domain $domain): string
    {
        return sprintf('v=TLSRPTv1; rua=mailto:%s', config('domains.tls_rpt_rua', 'tls-reports@ventmail.app'));
    }

    /** @return list<string> */
    private function mailExchangers(Domain $domain): array
    {
        try {
            $records = $this->dns->records((string) $domain->name, 'MX');
        } catch (DnsUnavailable) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($record) => trim((string) preg_replace('/^\s*\d+\s+/', '', (string) $record), '. '),
            $records,
        )));
    }
}
