<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Services;

use App\Modules\Deliverability\Contracts\BlocklistClient;
use App\Modules\Deliverability\Data\BlocklistVerdict;
use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Contracts\DnsUnavailable;

/**
 * A valódi DNSBL-lekérdezés: fordított IP + a zóna neve.
 *
 * A `203.0.113.5` a `zen.spamhaus.org`-on `5.113.0.203.zen.spamhaus.org` — ha erre van
 * A-rekord, az IP listán van, és a VÁLASZ ÉRTÉKE mondja meg, melyik alistán.
 *
 * A `127.255.255.x` válaszok NEM találatok: a szolgáltatók így jelzik a saját hibáikat (a
 * `127.255.255.254` a „nyilvános resolveren keresztül kérdeztél", a `.252` a „lekérdezés-keret
 * túllépve"). Aki ezeket találatnak veszi, hamis riasztást ad a saját ügyfeleire — és a
 * riasztás legrosszabb fajtája az, amiben nem lehet megbízni.
 */
final class DnsBlocklistClient implements BlocklistClient
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function check(string $target, string $zone): BlocklistVerdict
    {
        $query = $this->query($target, $zone);

        if ($query === null) {
            return BlocklistVerdict::inconclusive('a cél nem kérdezhető le');
        }

        try {
            $records = $this->dns->records($query, 'A');
        } catch (DnsUnavailable $e) {
            // A DNS kiesése NEM azt jelenti, hogy tiszták vagyunk.
            return BlocklistVerdict::inconclusive($e->getMessage());
        }

        if ($records === []) {
            return BlocklistVerdict::clean();
        }

        foreach ($records as $record) {
            // A szolgáltató SAJÁT hibajelzései: nem találatok.
            if (str_starts_with((string) $record, '127.255.255.')) {
                return BlocklistVerdict::inconclusive('a szolgáltató hibajelzése: '.$record);
            }
        }

        return BlocklistVerdict::listed(implode(', ', $records));
    }

    private function query(string $target, string $zone): ?string
    {
        if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return implode('.', array_reverse(explode('.', $target))).'.'.$zone;
        }

        // Domain-alapú lista (`dbl.spamhaus.org`): a nevet közvetlenül fűzzük.
        return preg_match('/^[A-Za-z0-9.-]+$/', $target) === 1 ? $target.'.'.$zone : null;
    }
}
