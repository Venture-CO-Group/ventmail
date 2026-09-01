<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Identity modul — ventSSO OIDC kliens: login-callback, token-verifikáció, szerep-leképezés. Jelszót SOHA nem kezel.
 */
final class IdentityServiceProvider extends ServiceProvider
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
