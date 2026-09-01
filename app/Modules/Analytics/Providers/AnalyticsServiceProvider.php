<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Analytics modul — Marketing analitika Timescale aggregátumokból. UGYANARRA az eseményforrásra épül, mint a napló.
 */
final class AnalyticsServiceProvider extends ServiceProvider
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
