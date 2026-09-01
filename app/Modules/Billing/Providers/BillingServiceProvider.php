<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Billing modul — ventPay metering (6 dimenzió) és entitlement. Kártyaadatot SOHA nem lát.
 */
final class BillingServiceProvider extends ServiceProvider
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
