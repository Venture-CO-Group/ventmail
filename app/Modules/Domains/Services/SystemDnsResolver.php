<?php

declare(strict_types=1);

namespace App\Modules\Domains\Services;

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Contracts\DnsUnavailable;

/**
 * Éles DNS-feloldás a rendszer resolverén keresztül.
 *
 * FONTOS a kontraktus: TXT esetén rekordonként EGY, MÁR ÖSSZEFŰZÖTT értéket adunk vissza —
 * a PHP `dns_get_record` a 255 bájtos darabokat az `entries` tömbben adja, és ha darabonként
 * adnánk tovább, a 2048 bites DKIM-kulcs ellenőrzése SOHA nem sikerülne.
 *
 * Bizalmi modell (dokumentált korlát): a rendszer resolvere nem ad DNSSEC-validációs
 * jelzést. Cache-poisoning ellen a következő lépés egy DoH/DoT resolver az `AD` bit
 * ellenőrzésével — ez az ops-sáv feladata, és a `config/domains.php` rögzíti a döntést.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function records(string $name, string $type): array
    {
        $constant = match (strtoupper($type)) {
            'TXT' => DNS_TXT,
            'CNAME' => DNS_CNAME,
            'MX' => DNS_MX,
            default => DNS_A,
        };

        $records = @dns_get_record($name, $constant);

        if ($records === false) {
            // A LEKÉRDEZÉS bukott (SERVFAIL, időtúllépés, resolver-kiesés) — ez NEM azt
            // jelenti, hogy nincs ilyen rekord. Az üres tömb visszaadása a hívónak azt
            // mondaná, hogy „megnéztem, nincs ott", és a címellenőrzés ebből 1.0 kockázatot
            // számolna: egy DNS-kimaradás a teljes platform küldését állítaná meg.
            throw new DnsUnavailable(sprintf('A(z) %s %s lekérdezése nem futott le.', $name, $type));
        }

        return array_values(array_filter(array_map(
            static function (array $record): ?string {
                // A TXT darabjait ÖSSZEFŰZVE adjuk vissza (lásd a kontraktust).
                if (isset($record['entries']) && is_array($record['entries'])) {
                    return implode('', $record['entries']);
                }

                // A KULCS rekordtípusonként más: TXT → `txt`, CNAME/MX → `target`,
                // A → `ip`, AAAA → `ipv6`. Az `ip` hiánya csendes hiba volt: minden
                // A-lekérdezés ÜRES listát adott, és a DNSBL-kliens ebből „tiszta" ítéletet
                // olvasott — a blocklist-monitor élesben soha nem talált volna semmit.
                return $record['txt'] ?? $record['target'] ?? $record['ip'] ?? $record['ipv6'] ?? null;
            },
            $records
        )));
    }
}
