<?php

declare(strict_types=1);

namespace App\Modules\Logs\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Logs modul — Kereshető napló és üzenet-trace (SMTP-párbeszéddel), retenció-kezelés.
 */
final class LogsServiceProvider extends ServiceProvider
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
