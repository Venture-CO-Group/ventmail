<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts;
use RuntimeException;

/**
 * Élesben csak a beállított hostokra szolgálunk ki — minden más Host-fejléc 400.
 *
 * Üres lista esetén a Symfony MINDEN hostot elfogadna: ezért ott fail-closed (500), nem
 * csendes kinyitás.
 */
final class TrustConfiguredHosts extends TrustHosts
{
    public function hosts(): array
    {
        $hosts = (array) config('proxy.trusted_hosts', []);

        if ($hosts === []) {
            throw new RuntimeException('Nincs megbízható host (TRUSTED_HOSTS / APP_URL) — kiszolgálás megtagadva.');
        }

        return array_map(static fn (string $host) => '^'.preg_quote($host).'$', $hosts);
    }

    protected function shouldSpecifyTrustedHosts(): bool
    {
        return $this->app->environment('production');
    }
}
