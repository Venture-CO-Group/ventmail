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
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, array<string, list<string>>> */
    private array $zone = [];

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

    public function records(string $name, string $type): array
    {
        return $this->zone[strtolower($name)][strtoupper($type)] ?? [];
    }
}
