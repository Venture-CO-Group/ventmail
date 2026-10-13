<?php

declare(strict_types=1);

use App\Modules\Suppression\Http\Controllers\SuppressionApiController;
use Illuminate\Support\Facades\Route;

/*
 | `/v1/suppressions` (v1.1 · 7. modul) — a tiltólista gépi felülete.
 |
 | ÚJ végpontok (additív bővítés, a meglévő `/v1` szerződés változatlan). A szabályok
 | ugyanazok, mint a felületen, mert ugyanazt a `SuppressionStore`-t hívják: platform-szintű
 | tiltás nem érinthető, a panasz és a leiratkozás nem törölhető, a törlés indoklást kér.
 */
Route::get('/suppressions', [SuppressionApiController::class, 'index'])
    ->middleware('api.scope:suppressions:read')
    ->name('v1.suppressions.index');

Route::post('/suppressions', [SuppressionApiController::class, 'store'])
    ->middleware('api.scope:suppressions:write')
    ->name('v1.suppressions.store');

Route::delete('/suppressions/{email}', [SuppressionApiController::class, 'destroy'])
    ->middleware('api.scope:suppressions:write')
    ->where('email', '[^/]{3,320}')
    ->name('v1.suppressions.destroy');
