<?php

declare(strict_types=1);

namespace App\Modules\IpPools\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * IpPools modul — Dedikált IP-k poolokba szervezve, warmup-motor, rotáció, karantén, reputáció.
 */
final class IpPoolsServiceProvider extends ServiceProvider
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
