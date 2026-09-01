<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Deliverability modul — Blocklist-monitor, DMARC RUA, seed/inbox placement, spam-score, MTA-STS/TLS-RPT/BIMI.
 */
final class DeliverabilityServiceProvider extends ServiceProvider
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
