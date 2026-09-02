<?php

declare(strict_types=1);

namespace App\Modules\Domains\Providers;

use App\Modules\Domains\Console\PurgeDkimKeysCommand;
use App\Modules\Domains\Console\VerifyDomainsCommand;
use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Services\SystemDnsResolver;
use App\Modules\Domains\Testing\FakeDnsResolver;
use App\Modules\Support\ModuleServiceProvider;
use InvalidArgumentException;
use RuntimeException;

/**
 * Domains modul — küldő domainek hitelesítése (SPF/DKIM/DMARC), tracking domain, DNS-check.
 */
final class DomainsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifyDomainsCommand::class,
                PurgeDkimKeysCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        $this->app->singleton(DnsResolver::class, function () {
            $driver = (string) config('domains.resolver');

            // A hitelesítés BIZALMI ORÁKULUMA nem lehet fake élesben: a FakeDnsResolver
            // publikus `publish()`-sal rendelkezik, tehát a domain-tulajdon bizonyítása egy
            // alkalmazás-kódból felülírható tömb lenne (golden rule 8 megkerülése).
            if ($driver === 'fake' && $this->app->isProduction()) {
                throw new RuntimeException(
                    'Éles környezetben a fake DNS-resolver nem használható: a domain-hitelesítés '
                    .'bizalmi orákuluma nem lehet in-memory tömb. Állítsd: DOMAINS_RESOLVER=system.'
                );
            }

            return match ($driver) {
                'system' => new SystemDnsResolver,
                'fake' => new FakeDnsResolver,
                default => throw new InvalidArgumentException("Ismeretlen DOMAINS_RESOLVER: {$driver} (system|fake)"),
            };
        });
    }
}
