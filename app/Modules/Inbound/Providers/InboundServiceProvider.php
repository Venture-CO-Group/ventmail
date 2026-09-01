<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Inbound modul — Bejövő e-mail: MX-fogadás, MIME-parser SANDBOXBAN, route-motor, stripped-text, csatolmány.
 */
final class InboundServiceProvider extends ServiceProvider
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
