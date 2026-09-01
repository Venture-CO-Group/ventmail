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
