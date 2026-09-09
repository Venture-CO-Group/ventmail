<?php

declare(strict_types=1);

use App\Modules\Validation\Http\Controllers\ValidationController;
use Illuminate\Support\Facades\Route;

/*
 | A5 — címellenőrzés. A `validate:run` scope külön értékesíthető szolgáltatás (spec 12).
 */

Route::post('/validate', [ValidationController::class, 'validateOne'])
    ->middleware('api.scope:validate:run')
    ->name('v1.validate');

/*
 | A KÖTEGELT ellenőrzés CSOMAG-FUNKCIÓ (C1 · 76. kritérium).
 |
 | Az EGYEDI ellenőrzés (`POST /v1/validate`) mindig elérhető: az a küldés előtti higiénia
 | része, és annak korlátozása a kézbesíthetőség ellen dolgozna. A KÖTEGELT viszont
 | tízezres listákat futtat MX- és mailbox-szondával — ez az, ami erőforrást és RBL-
 | kockázatot visz, tehát csomaghoz kötött.
 |
 | A `entitlement` middleware a `api.scope` UTÁN áll: előbb derüljön ki, hogy a kulcs
 | egyáltalán jogosult-e a műveletre (403), és csak utána, hogy a csomag tartalmazza-e
 | (402). A fordított sorrend egy jogosulatlan kulcsnak elárulná a csomag tartalmát.
 */
Route::post('/validate/bulk', [ValidationController::class, 'bulk'])
    ->middleware(['api.scope:validate:run', 'entitlement:validation_bulk'])
    ->name('v1.validate.bulk');

Route::get('/validate/bulk/{uuid}', [ValidationController::class, 'show'])
    ->middleware('api.scope:validate:run')
    ->name('v1.validate.bulk.show');

Route::get('/validate/bulk/{uuid}/export', [ValidationController::class, 'export'])
    ->middleware('api.scope:validate:run')
    ->name('v1.validate.bulk.export');
