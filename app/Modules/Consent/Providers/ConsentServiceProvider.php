<?php

declare(strict_types=1);

namespace App\Modules\Consent\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Consent modul — Double opt-in, jogalap, forrás, ip_hash, verzió. Marketing csak érvényes consenttel.
 */
final class ConsentServiceProvider extends ServiceProvider
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
