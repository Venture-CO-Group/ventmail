<?php

declare(strict_types=1);

namespace App\Modules\Audience\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Audience modul — Kontaktok, listák, tagek, groupok, dinamikus szegmensek (SegmentEngine).
 */
final class AudienceServiceProvider extends ServiceProvider
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
