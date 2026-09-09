<?php

declare(strict_types=1);

use App\Modules\Domains\Http\Controllers\DomainController;
use App\Modules\Domains\Http\Controllers\DomainWizardController;
use Illuminate\Support\Facades\Route;

/*
 | A modul-provider a kötelező stackkel regisztrálja (web + auth + workspace); a
 | SZEREP-ellenőrzés a kontrollerekben van (`domain.manage`).
 |
 | Minden állapotot módosító művelet POST: GET-en entitást és kulcsanyagot létrehozni
 | CSRF-védelem nélkül lehetne.
 */

Route::get('/domains', [DomainController::class, 'index'])->name('domains.index');
Route::get('/domains/wizard', DomainWizardController::class)->name('domains.wizard');
/*
 | A SAJÁT KÜLDŐ DOMAIN CSOMAG-FUNKCIÓ (C1 · 76. kritérium).
 |
 | A review megmérte, hogy a `custom_domain`-t SEHOL nem kérdezte meg senki: egy csomag,
 | ami nem tartalmazza a saját domaint, ingyen megkapta. A `store` és a `verify` az, ami a
 | domaint HASZNÁLHATÓVÁ teszi — a listázás és a wizard megnyitása nyitva marad, mert a
 | tenantnak látnia kell, mi van beállítva nála (lefokozás után is).
 |
 | A DKIM-rotáció is védett: az egy MEGLÉVŐ, verifikált domain műveletе, tehát a funkció
 | része — és a rotáció a küldés folytonosságát érinti, nem csak a beállítást.
 */
Route::post('/domains', [DomainController::class, 'store'])
    ->middleware('entitlement:custom_domain')
    ->name('domains.store');
Route::post('/domains/{uuid}/verify', [DomainController::class, 'verify'])
    ->middleware('entitlement:custom_domain')
    ->name('domains.verify');
Route::post('/domains/{uuid}/dkim/rotate', [DomainController::class, 'rotateDkim'])
    ->middleware('entitlement:custom_domain')
    ->name('domains.dkim.rotate');
