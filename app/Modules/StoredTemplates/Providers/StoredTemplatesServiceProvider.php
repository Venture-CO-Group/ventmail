<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * StoredTemplates modul — Tárolt sablonok API verziózással (Platform-oldal, a Template modul a Studio-oldal).
 */
final class StoredTemplatesServiceProvider extends ServiceProvider
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
