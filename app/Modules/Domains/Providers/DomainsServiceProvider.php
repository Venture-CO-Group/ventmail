<?php

declare(strict_types=1);

namespace App\Modules\Domains\Providers;

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Testing\FakeDnsResolver;
use App\Modules\Support\ModuleServiceProvider;

/**
 * Domains modul — küldő domainek hitelesítése (SPF/DKIM/DMARC), tracking domain, DNS-check.
 */
final class DomainsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // A valódi resolver az ops-sávban jön (élő DNS); a fake determinisztikus, és a
        // hitelesítési ÁLLAPOTGÉPET teljesen tesztelhetővé teszi.
        $this->app->singleton(DnsResolver::class, FakeDnsResolver::class);
    }
}
