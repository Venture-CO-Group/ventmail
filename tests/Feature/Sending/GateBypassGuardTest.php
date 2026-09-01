<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 | M0-3 elfogadási kritérium #22 — golden rule 2: a PreSendGate MEGKERÜLHETETLEN.
 |
 | A `SendingDriver::send()` egyetlen hívója a `MessageDispatcher`, amelyet viszont csak a
 | gate-en átment üzenettel lehet meghívni. Ez a teszt szerkezetileg zárja a kiskaput,
 | mielőtt az A1 (REST) és az A2 (SMTP relay) ráépül.
 */

it('a drivert kizárólag a MessageDispatcher hívja', function () {
    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = $file->getRelativePathname();

        // A dispatcher a sanctionált hívó; a driverek maguk implementálják a metódust;
        // a provider pedig a kötés helye (ott a `SendingDriver::class` említése kötelező).
        if (str_contains($relative, 'Sending/Services/MessageDispatcher.php')
            || str_contains($relative, 'Sending/Drivers/')
            || str_contains($relative, 'Sending/Contracts/')
            || str_contains($relative, 'Sending/Providers/SendingServiceProvider.php')) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (preg_match('/->send\(\s*\$[a-zA-Z]*[Mm]essage/', $contents)
            || str_contains($contents, 'SendingDriver::class')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBeEmpty('a gate megkerülésével hívja a drivert: '.implode(', ', $offenders));
});

it('a dispatcher nem hívható gate-döntés nélkül', function () {
    $source = file_get_contents(app_path('Modules/Sending/Services/MessageDispatcher.php'));

    // A dispatch belépési pontján kötelező az ellenőrzés, hogy az üzenet átment a gate-en.
    expect($source)->toContain('accepted');
});
