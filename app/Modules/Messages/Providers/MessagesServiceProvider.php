<?php

declare(strict_types=1);

namespace App\Modules\Messages\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Messages modul — Messages API (Mailgun-paritás): strukturált küldés, MIME, batch, o:/h:/v: vezérlők.
 */
final class MessagesServiceProvider extends ServiceProvider
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
