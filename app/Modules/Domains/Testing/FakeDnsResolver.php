<?php

declare(strict_types=1);

namespace App\Modules\Domains\Testing;

use App\Modules\Domains\Contracts\DnsResolver;

/**
 * Determinisztikus resolver a teszthez és a fejlesztéshez.
 *
 * A valódi DNS-terjedés percekig tart és kívül esik a rendszeren — a hitelesítési
 * ÁLLAPOTGÉP viszont enélkül is teljesen tesztelhető, beleértve a `verified → failed`
 * visszaesést, amit élesben csak véletlenül lehetne előidézni.
 *
 * ALAPÉRTELMEZÉSBEN A VILÁG FELOLDHATÓ: az MX- és A-lekérdezés minden nem publikált domainre
 * ad választ. Ez nem lazaság, hanem a fake helyes szerződése — ugyanaz a minta, mint a
 * `FakeHostResolver`-é (`203.0.113.10`). A tesztek túlnyomó része a KÜLDÉST méri, nem a
 * DNS-t; ha az alapállapot a „semmi nem létezik" lenne, minden küldő teszt csendben a
 * hiányzó MX-en bukna el, és a hiba oka a fake-ben lenne, nem a kódban.
 *
 * Ami NEM létezik, azt a teszt EXPLICIT mondja meg (`withdraw()` vagy `blackhole()`) — így a
 * „nincs MX" eset is méréssel, nem alapértelmezéssel bizonyítódik.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, array<string, list<string>>> */
    private array $zone = [];

    /** @var list<string> a domainek, amikre SEMMI nem oldódik fel */
    private array $blackholed = [];

    /**
     * A hitelesítéshez tartozó rekordtípusok: ezekre NINCS alapértelmezés.
     *
     * A `verified` státusz bizonyíték — ha a fake magától adna DKIM- vagy SPF-rekordot,
     * minden domain hitelesnek látszana, és a hitelesítés tesztje semmit nem érne.
     *
     * @var list<string>
     */
    private const NO_DEFAULT = ['TXT', 'CNAME'];

    public function publish(string $name, string $type, string $value): void
    {
        $this->zone[strtolower($name)][strtoupper($type)][] = $value;
    }

    public function withdraw(string $name, ?string $type = null): void
    {
        $key = strtolower($name);

        if ($type === null) {
            unset($this->zone[$key]);

            return;
        }

        unset($this->zone[$key][strtoupper($type)]);
    }

    /** Erre a domainre SEMMI nem oldódik fel — a „nincs MX" eset explicit előidézése. */
    public function blackhole(string $name): void
    {
        $this->blackholed[] = strtolower($name);
    }

    public function records(string $name, string $type): array
    {
        $key = strtolower($name);
        $type = strtoupper($type);

        if (in_array($key, $this->blackholed, true)) {
            return [];
        }

        if (isset($this->zone[$key][$type])) {
            return $this->zone[$key][$type];
        }

        // A publikált domainnek CSAK a publikált rekordjai vannak: ha a teszt egyszer
        // beszélt erről a névről, akkor a hiányzó típus tényleg hiányzik.
        if (isset($this->zone[$key]) || in_array($type, self::NO_DEFAULT, true)) {
            return [];
        }

        return match ($type) {
            'MX' => ['10 mx.'.$key.'.'],
            'A' => ['203.0.113.10'],
            default => [],
        };
    }
}
