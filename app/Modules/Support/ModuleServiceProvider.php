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
    /**
     * A KÖTELEZŐ alap, amit modul nem vehet el (security-review M2): a `protected` property
     * önmagában felülírható lett volna üres tömbre, és a route middleware nélkül regisztrált.
     * A modul csak BŐVÍTHETI a stacket.
     */
    private const REQUIRED_WEB = ['web', 'auth', 'workspace'];

    private const REQUIRED_API = ['api', 'throttle:api', 'auth', 'workspace'];

    /**
     * Kivétel a kötelező stack alól — KIZÁRÓLAG a bejelentkezési folyamathoz, ahol még
     * nincs sem user, sem workspace-kontextus. A modul ezt csak akkor kapcsolhatja be,
     * ha a route-jai valóban hitelesítés ELŐTTIEK (Identity: /auth/*).
     */
    protected bool $routesPrecedeAuthentication = false;

    /** A modul által HOZZÁADOTT web-middleware-ek. */
    protected array $webMiddleware = [];

    /** A modul által HOZZÁADOTT api-middleware-ek. */
    protected array $apiMiddleware = [];

    /** URL-prefix a modul API-route-jaihoz (a `/v1` felület az Api modul gazdája). */
    protected string $apiPrefix = '';

    public function register(): void
    {
        //
    }

    final public function boot(): void
    {
        $this->loadModuleMigrations();
        $this->registerModuleRoutes();
        $this->bootModule();
    }

    /** A modul saját boot-logikája (a route- és migráció-regisztráció nem írható felül). */
    protected function bootModule(): void
    {
        //
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

    final protected function registerModuleRoutes(): void
    {
        $web = $this->modulePath('routes/web.php');

        if (file_exists($web)) {
            Route::middleware($this->webStack())->group($web);
        }

        $api = $this->modulePath('routes/api.php');

        if (file_exists($api)) {
            Route::middleware($this->apiStack())
                ->prefix($this->apiPrefix)
                ->group($api);
        }
    }

    /** @return list<string> */
    private function webStack(): array
    {
        $required = $this->routesPrecedeAuthentication ? ['web'] : self::REQUIRED_WEB;

        return array_values(array_unique([...$required, ...$this->webMiddleware]));
    }

    /**
     * @return list<string>
     */
    private function apiStack(): array
    {
        // A `/v1` felület API-KULCCSAL hitelesít, nem böngésző-session-nel: ott az `auth` és a
        // `workspace` middleware nem értelmezett, a kontextus magából a kulcsból származik.
        // A hitelesítés viszont NEM opcionális — a modulnak meg kell adnia a saját őrét
        // (`api.auth`), és ezt teszt kényszeríti ki.
        $required = $this->routesPrecedeAuthentication ? ['api', 'throttle:api'] : self::REQUIRED_API;

        return array_values(array_unique([...$required, ...$this->apiMiddleware]));
    }
}
