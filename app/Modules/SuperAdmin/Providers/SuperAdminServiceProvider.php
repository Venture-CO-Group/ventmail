<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * SuperAdmin modul — Operátori konzol: plan-override, globális suppression, cluster health, auditált impersonation.
 */
final class SuperAdminServiceProvider extends ServiceProvider
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
