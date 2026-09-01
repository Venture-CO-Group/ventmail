<?php

declare(strict_types=1);

namespace App\Modules\Template\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Template modul — Studio block-tree (közös JSON Schema) → MJML → reszponzív HTML + plaintext.
 */
final class TemplateServiceProvider extends ServiceProvider
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
