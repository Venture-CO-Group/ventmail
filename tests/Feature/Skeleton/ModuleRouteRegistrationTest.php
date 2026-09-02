<?php

declare(strict_types=1);

use App\Modules\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
 | A modul-providerek NEM regisztrálhatnak route-ot csupasz loadRoutesFrom()-mal:
 | az semmilyen middleware-t nem alkalmaz (se web/api, se auth, se throttle, se CSRF),
 | így az első modul-route hitelesítés, tenant-scope és rate-limit NÉLKÜL szolgálna ki.
 | Golden rule 2/5/6 szerkezeti kiskapuja — ezért közös ős kényszeríti a middleware-stacket.
 */

it('egyetlen modul-provider sem hív csupasz loadRoutesFrom-ot', function () {
    $offenders = [];

    foreach (File::allFiles(app_path('Modules')) as $file) {
        if (! str_ends_with($file->getFilename(), 'ServiceProvider.php')) {
            continue;
        }

        // A közös ős maga sem használ csupasz loadRoutesFrom-ot, de őt külön teszt fedi.
        if ($file->getRelativePathname() === 'Support/ModuleServiceProvider.php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (str_contains($contents, 'loadRoutesFrom')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBeEmpty(
        'csupasz loadRoutesFrom (middleware nélküli route-regisztráció): '.implode(', ', $offenders)
    );
});

it('minden modul-provider a közös ősből származik', function () {
    foreach (File::allFiles(app_path('Modules')) as $file) {
        if (! str_ends_with($file->getFilename(), 'ServiceProvider.php')) {
            continue;
        }

        if ($file->getRelativePathname() === 'Support/ModuleServiceProvider.php') {
            continue;
        }

        $module = basename(dirname($file->getPath()));
        $class = "App\\Modules\\{$module}\\Providers\\".$file->getFilenameWithoutExtension();

        expect(is_subclass_of($class, ModuleServiceProvider::class))
            ->toBeTrue("{$class} nem a ModuleServiceProvider ősből származik");
    }
});

it('a modul-route-ok mindig middleware-stackkel regisztrálnak', function () {
    $source = file_get_contents(app_path('Modules/Support/ModuleServiceProvider.php'));

    expect($source)
        ->toContain('Route::middleware(')
        ->toContain('->group(');
});

it('a kötelező middleware-alapot a modul nem veheti el', function () {
    // A `protected array` felülírható lett volna üres tömbre (security-review M2):
    // a kötelező alap ezért konstans, a regisztráló metódus final.
    $source = file_get_contents(app_path('Modules/Support/ModuleServiceProvider.php'));

    expect($source)
        ->toContain('private const REQUIRED_WEB')
        ->toContain('private const REQUIRED_API')
        ->toContain('final protected function registerModuleRoutes')
        ->toContain('final public function boot');
});

it('egyetlen modul-route sem hitelesítetlen', function () {
    // Két legitim hitelesítési út van, és MINDEN modul-route-nak az egyiken kell lennie:
    //   - böngésző-session:  `auth` + `workspace` (a kontextus a tagságból jön)
    //   - `/v1` API-kulcs:   `api.auth`           (a kontextus MAGÁBÓL a kulcsból jön)
    // Kivétel csak a bejelentkezési folyamat, ahol még nincs se user, se kulcs.
    $allowlist = ['auth/redirect', 'auth/callback', 'auth/logout', 'auth/dev-login', 'up', '/'];

    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        $action = $route->getAction('controller') ?? $route->getActionName();

        if (! str_contains((string) $action, 'App\\Modules\\') || in_array($route->uri(), $allowlist, true)) {
            continue;
        }

        $middleware = $route->gatherMiddleware();

        $sessionAuth = in_array('auth', $middleware, true) && in_array('workspace', $middleware, true);
        $apiKeyAuth = in_array('api.auth', $middleware, true);

        if (! $sessionAuth && ! $apiKeyAuth) {
            $offenders[] = $route->uri().' ['.implode(', ', $middleware).']';
        }
    }

    expect($offenders)->toBeEmpty('hitelesítetlen modul-route: '.implode(' | ', $offenders));
});

it('minden küldő /v1 POST idempotencia-kulcsot követel', function () {
    // Golden rule 5: a küldő POST-on KÖTELEZŐ. Ha egy új endpoint kimaradna, a hálózat
    // megismételt kérése duplán kézbesítene.
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('POST', $route->methods(), true) || ! str_starts_with($route->uri(), 'v1/messages')) {
            continue;
        }

        if (! in_array('api.idempotency', $route->gatherMiddleware(), true)) {
            $offenders[] = $route->uri();
        }
    }

    expect($offenders)->toBeEmpty('idempotencia-kulcs nélküli küldő endpoint: '.implode(', ', $offenders));
});

it('a kötelező stack tartalmazza a hitelesítést és a tenant-kontextust', function () {
    $source = file_get_contents(app_path('Modules/Support/ModuleServiceProvider.php'));

    expect($source)->toContain("'auth'")->toContain("'workspace'");
});

it('futásidőben minden modul-route middleware-csoportban van', function () {
    // Nem sztringkeresés: a tényleges route-táblát nézzük.
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        $action = $route->getAction('controller') ?? $route->getActionName();

        if (! str_contains((string) $action, 'App\\Modules\\')) {
            continue;
        }

        $middleware = $route->gatherMiddleware();

        if (! in_array('web', $middleware, true) && ! in_array('api', $middleware, true)) {
            $offenders[] = $route->uri();
        }
    }

    expect($offenders)->toBeEmpty('middleware-csoport nélküli modul-route: '.implode(', ', $offenders));
});

it('minden /v1 route-on van scope-ellenőrzés', function () {
    // A scope a jogosultság EGYETLEN kapuja az API-felületen: egy kimaradt endpoint
    // bármely érvényes kulccsal elérhető lenne, függetlenül attól, mire adták ki.
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'v1/')) {
            continue;
        }

        $hasScope = collect($route->gatherMiddleware())
            ->contains(fn (string $middleware) => str_starts_with($middleware, 'api.scope:'));

        if (! $hasScope) {
            $offenders[] = $route->methods()[0].' '.$route->uri();
        }
    }

    expect($offenders)->toBeEmpty('scope-ellenőrzés nélküli /v1 route: '.implode(', ', $offenders));
});

it('minden küldő útvonal idempotencia-kulcsot követel — a route NEVE alapján', function () {
    // URI-prefix helyett a route NEVE a jelölés: egy jövőbeli `POST /v1/campaigns/{id}/send`
    // különben átcsúszna az őrszemen.
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'v1.') || ! str_ends_with($name, '.send')) {
            continue;
        }

        if (! in_array('api.idempotency', $route->gatherMiddleware(), true)) {
            $offenders[] = $name;
        }
    }

    expect($offenders)->toBeEmpty('idempotencia-kulcs nélküli küldő útvonal: '.implode(', ', $offenders));
});
