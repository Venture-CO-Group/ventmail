<?php

declare(strict_types=1);

namespace App\Modules\Api\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Api modul — Publikus /v1 felület: verziózás, hitelesítés, rate-limit, OpenAPI ↔ @venture/sdk drift = 0.
 */
final class ApiServiceProvider extends ServiceProvider
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
