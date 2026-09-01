<?php

declare(strict_types=1);

namespace App\Modules\Sending\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Sending modul — A küldés szíve: SendingDriver interfész, PreSendGate (6 kapu), sorok, retry, bounce-osztályozás.
 */
final class SendingServiceProvider extends ServiceProvider
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
