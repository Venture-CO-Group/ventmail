<?php

declare(strict_types=1);

/*
 | LICENC-KAPU (v1.1 plan §2, D178; a 13. pont „licencellenőrzés: nincs GPL vagy AGPL").
 |
 | Minden függőség (composer.lock és package-lock.json, futásidejű ÉS fejlesztői) licence a
 | megengedő halmazból: MIT, BSD-2/3-Clause, Apache-2.0, ISC, 0BSD. Több licencű csomagnál
 | (composer: a `license` tömb VAGY-kapcsolat; npm: `(A OR B)`) elég, ha az egyik megengedett.
 | GPL / AGPL / LGPL egyedüli licencként tilos.
 |
 | Dokumentált kivétel: a Venture SAJÁT, belső csomagjai (`@venture/*`, a szomszédos
 | repókból linkelve, `UNLICENSED` = tulajdonosi kód, nem harmadik fél).
 */

const VM_ALLOWED_LICENSES = ['MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0', 'ISC', '0BSD'];

/** @param list<string> $options */
function vmLicenseAllowed(array $options): bool
{
    return array_intersect($options, VM_ALLOWED_LICENSES) !== [];
}

it('a composer.lock minden csomagja megengedő licencű', function () {
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);
    $offenders = [];

    foreach (['packages', 'packages-dev'] as $section) {
        foreach ($lock[$section] ?? [] as $package) {
            if (! vmLicenseAllowed((array) ($package['license'] ?? []))) {
                $offenders[] = $package['name'].' ('.implode('|', (array) ($package['license'] ?? [])).')';
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('a package-lock.json minden csomagja megengedő licencű (a saját @venture/* és @venture-co-group/* kivételével)', function () {
    $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true);
    $offenders = [];

    foreach ($lock['packages'] ?? [] as $path => $package) {
        if ($path === '' || str_starts_with($path, '../venture-') || str_starts_with($path, 'node_modules/@venture/') || str_starts_with($path, 'node_modules/@venture-co-group/')) {
            continue;
        }

        $license = (string) ($package['license'] ?? '');
        $options = preg_split('/\s+(?:OR)\s+/', trim($license, '() ')) ?: [];

        if (! vmLicenseAllowed($options)) {
            $offenders[] = $path.' ('.$license.')';
        }
    }

    expect($offenders)->toBe([]);
});

it('egyetlen függőség sem KIZÁRÓLAG GPL / AGPL / LGPL', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.lock')), true);

    foreach (array_merge($composer['packages'] ?? [], $composer['packages-dev'] ?? []) as $package) {
        $licenses = (array) ($package['license'] ?? []);
        $copyleftOnly = $licenses !== [] && array_filter($licenses, fn (string $l) => preg_match('/^(A|L)?GPL/i', $l) !== 1) === [];

        expect($copyleftOnly)->toBeFalse($package['name']);
    }
});
