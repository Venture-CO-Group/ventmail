<?php

declare(strict_types=1);

namespace App\Modules\Domains\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Domains modul — Küldő domainek hitelesítése: SPF/DKIM/DMARC/PTR/MTA-STS/BIMI, tracking domain, DNS-check.
 */
final class DomainsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $routes = __DIR__.'/../routes/routes.php';

        if (file_exists($routes)) {
            $this->loadRoutesFrom($routes);
        }
    }
}
