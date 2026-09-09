<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 | A PLATFORM-MODELLEK HATÁRA — a C2 nevesített előfeltétele, most kikényszerítve.
 |
 | A `PlanOverride` és a `ImpersonationSession` PLATFORM-adat: szándékosan NINCS rajtuk
 | workspace-scope (a platform-admin tenanteken átnyúlva dolgozik, és a `TenantModelGuardTest`
 | ezt kivételként fel is sorolja indoklással).
 |
 | A `PHASE.md` viszont ezt a konzol-packet előfeltételeként rögzítette: „az első
 | tenant-felületi lekérdezés minden tenant sorát látná". Ez a teszt azt a rést zárja be —
 | de NEM egy scope-pal.
 |
 | ## Miért nem scope
 |
 | Mert egy jogosultság-figyelő scope a RENDSZER-utakat is elvágná: a `PlanOverrides` a
 | küldési úton fut (a PreSendGate 4. kapuja hívja az `EntitlementChecker`-en át), ahol
 | nincs bejelentkezett platform-admin — se a queue-workerben, se egy tenant kérésében. Egy
 | „csak platform-adminnak látszik" szabály tehát pont a kvóta-override-ot kapcsolná ki,
 | csendben. A valódi kockázat nem a rendszer-út, hanem egy JÖVŐBELI TENANT-KÉPERNYŐ, ami
 | ezekre a modellekre kérdez rá — és ezt szerkezetileg lehet kizárni.
 */

it('a platform-modellekhez CSAK a SuperAdmin modul nyúlhat', function () {
    $models = ['ImpersonationSession', 'PlanOverride'];

    /*
     | A `PlanOverrides` SZOLGÁLTATÁS más modulokból is hívható (az `EntitlementChecker`
     | teszi is): az a kapuzott, auditált belépési pont. A MODELL viszont nem — aki
     | közvetlenül kérdez rá, az megkerüli a szolgáltatás szabályait (billable parent,
     | lejárat, visszavonás).
     */
    $offenders = [];

    foreach (File::allFiles(app_path('Modules')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = $file->getRelativePathname();

        if (str_starts_with($relative, 'SuperAdmin/')) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        foreach ($models as $model) {
            // A SZOLGÁLTATÁS neve (`PlanOverrides`, többes szám) nem találat: a keresés a
            // MODELL teljes hivatkozására megy.
            if (str_contains($contents, 'SuperAdmin\\Models\\'.$model)) {
                $offenders[] = $relative.' → '.$model;
            }
        }
    }

    expect($offenders)->toBeEmpty(
        'platform-modell a SuperAdmin modulon kívül: '.implode(', ', $offenders)
    );
});

it('a platform-modellek a TENANT-SCOPE kivétel-listáján vannak, indoklással', function () {
    // Ha valaki egyszer rájuk tenné a `BelongsToWorkspace`-t, a platform-admin saját
    // kontextus híján SEMMIT nem látna belőlük — a konzol kiürülne, és a hiba a
    // jogosultságnál látszana, nem a scope-nál.
    $guard = file_get_contents(base_path('tests/Feature/Schema/TenantModelGuardTest.php'));

    expect($guard)
        ->toContain('ImpersonationSession::class')
        ->toContain('PlanOverride::class');
});
