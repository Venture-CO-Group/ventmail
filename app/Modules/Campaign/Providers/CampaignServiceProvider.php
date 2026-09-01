<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Campaign modul — Kampány-életciklus, célzás, A/B variánsok, időzóna-tudatos ütemezés.
 */
final class CampaignServiceProvider extends ServiceProvider
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
