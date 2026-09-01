<?php

declare(strict_types=1);

namespace App\Modules\Events\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Events modul — Esemény-modell és Timescale hypertable (message_events). Az A sáv a gazda, a B csak olvas.
 */
final class EventsServiceProvider extends ServiceProvider
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
