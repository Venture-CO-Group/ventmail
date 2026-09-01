<?php

declare(strict_types=1);

namespace App\Modules\Forms\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Forms modul — Beiratkozó űrlapok (honeypot + time-trap) és preference center.
 */
final class FormsServiceProvider extends ServiceProvider
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
