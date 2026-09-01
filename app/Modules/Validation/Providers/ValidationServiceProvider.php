<?php

declare(strict_types=1);

namespace App\Modules\Validation\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Validation modul — Címellenőrzés: szintaxis, MX, szerepcím, eldobható, catch-all, mailbox-szonda, kockázat.
 */
final class ValidationServiceProvider extends ServiceProvider
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
