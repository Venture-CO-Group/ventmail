<?php

declare(strict_types=1);

use App\Modules\Audience\Http\Controllers\AudienceController;
use App\Modules\Audience\Http\Controllers\ContactController;
use App\Modules\Audience\Http\Controllers\ContactImportController;
use App\Modules\Audience\Http\Controllers\FormUiController;
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
    Route::post('/audience/lists/{uuid}', [AudienceController::class, 'updateList'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')->name('audience.lists.update');

    // A KONTAKT ADATLAPJA: mezők, listák, címkék, hozzájárulás-történet (import-forrással).
    Route::get('/audience/contacts/{uuid}', [ContactController::class, 'show'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')->name('audience.contacts.show');

    // IMPORT-VARÁZSLÓ (v1.1). Az állapot a szerveren él (`contact_imports`); minden lépés egy
    // POST + redirect, a háttérfeladat haladását a képernyő lekérdezi.
    Route::get('/audience/import', [ContactImportController::class, 'create'])->name('audience.import.create');
    // A feltöltés a legdrágább kérés (akár 100 MB, három menetes felmérés): percenként húsz (óránként
    // kétszáz) workspace-onként elég, és korlátozza a hitelesített, de rosszindulatú tag terhelését.
    Route::post('/audience/import', [ContactImportController::class, 'store'])
        ->middleware('throttle:audience-import')
        ->name('audience.import.store');
    Route::post('/audience/import/fields', [ContactImportController::class, 'storeField'])
        ->middleware('throttle:audience-import-step')
        ->name('audience.import.fields.store');

    Route::prefix('/audience/import/{uuid}')->where(['uuid' => '[0-9a-fA-F\-]{36}'])->group(function (): void {
        Route::get('/', [ContactImportController::class, 'show'])->name('audience.import.show');
        Route::post('/sheet', [ContactImportController::class, 'sheet'])->middleware('throttle:audience-import-sheet')->name('audience.import.sheet');
        Route::post('/mapping', [ContactImportController::class, 'mapping'])->middleware('throttle:audience-import-step')->name('audience.import.mapping');
        Route::post('/settings', [ContactImportController::class, 'settings'])->middleware('throttle:audience-import-step')->name('audience.import.settings');
        Route::post('/start', [ContactImportController::class, 'start'])->middleware('throttle:audience-import-step')->name('audience.import.start');
        Route::get('/errors', [ContactImportController::class, 'errors'])->name('audience.import.errors');
        Route::post('/cancel', [ContactImportController::class, 'cancel'])->name('audience.import.cancel');
    });
});

/*
 | ŰRLAP-ADMIN — a publikus `POST /f/{slug}` ellenpárja.
 |
 | Ugyanaz a stack, mint a közönség-képernyőé: a modul route-jai hitelesítés előttiek,
 | ezért az `auth` + `workspace` itt EXPLICIT.
 */
Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/forms', [FormUiController::class, 'index'])->name('forms.index');
    Route::post('/forms', [FormUiController::class, 'store'])->name('forms.store');
    Route::post('/forms/{uuid}', [FormUiController::class, 'update'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')->name('forms.update');
    Route::post('/forms/{uuid}/toggle', [FormUiController::class, 'toggle'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')->name('forms.toggle');
});
