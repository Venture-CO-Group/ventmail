<?php

declare(strict_types=1);

namespace App\Modules\Domains\Services;

use App\Modules\Domains\Contracts\DnsResolver;

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
            return [];
        }

        return array_values(array_filter(array_map(
            static function (array $record): ?string {
                // A TXT darabjait ÖSSZEFŰZVE adjuk vissza (lásd a kontraktust).
                if (isset($record['entries']) && is_array($record['entries'])) {
                    return implode('', $record['entries']);
                }

                return $record['txt'] ?? $record['target'] ?? null;
            },
            $records
        )));
    }
}
