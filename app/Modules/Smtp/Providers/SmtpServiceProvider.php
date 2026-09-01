<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Smtp modul — SMTP submission relay (587 STARTTLS / 465 TLS), per-domain hitelesítők. Nyílt relay TILOS.
 */
final class SmtpServiceProvider extends ServiceProvider
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
