<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 | Őrszem a global scope megkerülési útjaira. A Tenancy modul a scope gazdája; rajta kívül
 | senki nem kerülheti meg (golden rule 6: „global scope megkerülése" = instant blokk).
 */

it('a Tenancy modulon kívül senki nem kerüli meg a global scope-ot', function () {
    $offenders = [];

    foreach (File::allFiles(app_path('Modules')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = $file->getRelativePathname();

        // Dokumentált kivétel: az API-kulcs feloldása a kontextus FORRÁSA — lookup-időben
        // még nincs workspace. A kikapcsolás ott egyetlen, szűk, hash-egyezésre korlátozott
        // lekérdezésre szól, és teszt fedi (ApiKeyLookupTest).
        $exceptions = ['Identity/Models/ApiKey.php'];

        if (str_starts_with($relative, 'Tenancy/') || in_array($relative, $exceptions, true)) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        $bypasses = '/withoutGlobalScope|withoutGlobalScopes|->runFor\(|DB::table\(|DB::(select|statement|update|delete)\(/';

        if (preg_match($bypasses, $contents)) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBeEmpty('global scope megkerülése a Tenancy modulon kívül: '.implode(', ', $offenders));
});
