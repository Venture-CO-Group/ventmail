<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Testing;

use App\Modules\Webhooks\Contracts\HostResolver;

/**
 * Determinisztikus feloldás a tesztekhez.
 *
 * Alapértelmezés: publikus dokumentációs cím (203.0.113.x) — ezzel a „rendes" végpont
 * átmegy. A támadási eseteket a teszt EXPLICIT állítja be (127.0.0.1, 169.254.169.254…),
 * így a védelem valódi címekre mérve bizonyítható, hálózat nélkül.
 */
final class FakeHostResolver implements HostResolver
{
    /** @param array<string, list<string>> $map */
    public function __construct(private array $map = [], private string $default = '203.0.113.10') {}

    /** @param list<string> $addresses */
    public function map(string $host, array $addresses): void
    {
        $this->map[$host] = $addresses;
    }

    public function resolve(string $host): array
    {
        return $this->map[$host] ?? [$this->default];
    }
}
