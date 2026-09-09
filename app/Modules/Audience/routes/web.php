<?php

declare(strict_types=1);

use App\Modules\Audience\Http\Controllers\AudienceController;
use App\Modules\Audience\Http\Controllers\PublicFormController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

/*
 | PUBLIKUS ŰRLAP-BEKÜLDÉS (54. kritérium).
 |
 | Ezt az ügyfél weboldala küldi be, névtelen látogatóktól — hitelesítés itt nem értelmezett.
 | A CSRF-őr is kimarad: a beküldő oldal a MI domainünkön kívül van, tehát nincs honnan
 | tokent kapnia. Ez nem gyengítés, mert a végpont EGYETLEN hatása egy `pending` consent —
 | jogalapot nem teremt, levelet nem küld a beküldő választása szerint, és a suppression-lista
 | erősebb nála.
 |
 | Ami VÉD: a honeypot + time-trap (a feldolgozás elválaszthatatlan része), a rate-limit, és
 | hogy a válasz MINDIG ugyanaz.
 */
Route::post('/f/{slug}', PublicFormController::class)
    ->withoutMiddleware([
        VerifyCsrfToken::class,
    ])
    ->middleware('throttle:public-form')
    ->where('slug', '[A-Za-z0-9\-]{1,128}')
    ->name('forms.submit');

/*
 | A KÖZÖNSÉG FELÜLETE — a fentiek ellentéte.
 |
 | A publikus űrlapot névtelen látogató küldi be; ez a képernyő a bejelentkezett
 | marketingesé. A modul route-jai hitelesítés ELŐTTIEK, ezért a stack itt EXPLICIT: a
 | kapcsolat-lista személyes adatok tömege, tagság és szerep nélkül nem olvasható.
 */
Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/audience', [AudienceController::class, 'index'])->name('audience.index');
    Route::post('/audience/lists', [AudienceController::class, 'storeList'])->name('audience.lists.store');
});
