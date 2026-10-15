<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
 | M0-0 elfogadási kritérium #4:
 | mind a 25 modul váza létezik app/Modules/<Name>/ alatt README-vel.
 | A modultérkép forrása: docs/ventmail-specifikacio.md 2.3 és CLAUDE.md „Modulok".
 */

const VENTMAIL_MODULES = [
    // közös motor
    'Identity', 'Tenancy', 'Domains', 'IpPools', 'Sending', 'Events', 'Suppression',
    // PLATFORM (Mailgun-kiváltás)
    'Messages', 'Smtp', 'Inbound', 'Logs', 'Validation', 'Webhooks', 'Deliverability', 'StoredTemplates',
    // STUDIO (Mailchimp-kiváltás)
    'Audience', 'Consent', 'Template', 'Campaign', 'Journey', 'Forms', 'Analytics',
    // zárás
    'Billing', 'SuperAdmin', 'Api',
    // ventSec-kötés (m2m token, ítélet-ellenőrzés, VSES események)
    'VentSec',
];

it('mind a 26 modul vázát létrehozza', function () {
    expect(VENTMAIL_MODULES)->toHaveCount(26);

    foreach (VENTMAIL_MODULES as $module) {
        expect(is_dir(app_path("Modules/{$module}")))
            ->toBeTrue("hiányzó modul: app/Modules/{$module}");
    }
});

it('minden modulban van README és ServiceProvider', function () {
    foreach (VENTMAIL_MODULES as $module) {
        expect(is_file(app_path("Modules/{$module}/README.md")))
            ->toBeTrue("hiányzó README: app/Modules/{$module}/README.md");
        expect(is_file(app_path("Modules/{$module}/Providers/{$module}ServiceProvider.php")))
            ->toBeTrue("hiányzó ServiceProvider: {$module}");
    }
});

it('minden modul ServiceProvidere regisztrálva van', function () {
    $providers = app()->getLoadedProviders();

    foreach (VENTMAIL_MODULES as $module) {
        $class = "App\\Modules\\{$module}\\Providers\\{$module}ServiceProvider";
        expect($providers)->toHaveKey($class, "nem regisztrált provider: {$class}");
    }
});

it('a modulok a kanonikus alkönyvtár-szerkezetet követik', function () {
    $expected = ['Actions', 'Data', 'Database/Migrations', 'Events', 'Http/Controllers', 'Jobs',
        'Models', 'Policies', 'Providers', 'Services', 'routes'];

    foreach (VENTMAIL_MODULES as $module) {
        foreach ($expected as $dir) {
            expect(is_dir(app_path("Modules/{$module}/{$dir}")))
                ->toBeTrue("hiányzó könyvtár: app/Modules/{$module}/{$dir}");
        }
    }
});

it('a modulnevek megfelelnek a StudlyCase konvenciónak', function () {
    foreach (VENTMAIL_MODULES as $module) {
        expect(Str::studly($module))->toBe($module);
    }
});
