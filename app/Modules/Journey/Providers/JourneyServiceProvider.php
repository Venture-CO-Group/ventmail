<?php

declare(strict_types=1);

namespace App\Modules\Journey\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Journey modul — Customer Journey motor: trigger → condition → action → wait → branch, idempotens lépésekkel.
 */
final class JourneyServiceProvider extends ServiceProvider
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
