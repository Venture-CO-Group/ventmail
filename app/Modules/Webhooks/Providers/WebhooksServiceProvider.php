<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Webhooks modul — Kimenő webhookok: HMAC-SHA256 + timestamp, retry, dead-letter, replay.
 */
final class WebhooksServiceProvider extends ServiceProvider
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
