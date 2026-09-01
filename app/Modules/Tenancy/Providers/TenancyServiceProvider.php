<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Tenancy modul — Workspace-ek, parent↔child sub-accountok, workspace_members, global scope, Policy-k.
 */
final class TenancyServiceProvider extends ServiceProvider
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
