<?php

declare(strict_types=1);

use App\Modules\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\File;

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
