<?php

declare(strict_types=1);

namespace App\Modules\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Minden ventMail-modul ServiceProviderének közös őse.
 *
 * Miért kell: a csupasz `loadRoutesFrom()` SEMMILYEN middleware-t nem alkalmaz — se
 * `web`/`api`, se auth, se throttle, se CSRF. Egy modul-route így hitelesítés,
 * tenant-scope és rate-limit nélkül szolgálna ki (golden rule 2, 5, 6). Ezért a
 * route-regisztráció itt, kötelező middleware-stackkel történik; a modul csak a
 * stack NEVÉT és a prefixet választhatja, a regisztráció módját nem.
 *
 * A tenant- és auth-middleware-t az M0-1 (Identity + Tenancy) köti be a stackekbe;
 * addig a `web`/`api` csoport az alap. A `ModuleRouteRegistrationTest` őrzi, hogy
 * senki ne kerülje meg ezt az utat.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** A modul `routes/web.php`-jára alkalmazott stack. */
    protected array $webMiddleware = ['web'];

    /** A modul `routes/api.php`-jára alkalmazott stack. */
    protected array $apiMiddleware = ['api'];

    /** URL-prefix a modul API-route-jaihoz (a `/v1` felület az Api modul gazdája). */
    protected string $apiPrefix = '';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadModuleMigrations();
        $this->registerModuleRoutes();
    }

    /** A modul könyvtárának abszolút útvonala. */
    protected function modulePath(string $append = ''): string
    {
        $base = dirname((new \ReflectionClass($this))->getFileName(), 2);

        return $append === '' ? $base : $base.'/'.$append;
    }

    protected function loadModuleMigrations(): void
    {
        $path = $this->modulePath('Database/Migrations');

        if (is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }

    protected function registerModuleRoutes(): void
    {
        $web = $this->modulePath('routes/web.php');

        if (file_exists($web)) {
            Route::middleware($this->webMiddleware)->group($web);
        }

        $api = $this->modulePath('routes/api.php');

        if (file_exists($api)) {
            Route::middleware($this->apiMiddleware)
                ->prefix($this->apiPrefix)
                ->group($api);
        }
    }
}
