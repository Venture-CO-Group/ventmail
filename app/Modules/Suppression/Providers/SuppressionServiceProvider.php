<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Suppression modul — Globális és workspace-szintű suppression lista. GDPR-törlés után is MEGMARAD.
 */
final class SuppressionServiceProvider extends ServiceProvider
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
