<?php

declare(strict_types=1);

use App\Modules\Audience\Http\Controllers\AudienceController;
use App\Modules\Audience\Http\Controllers\ContactController;
use App\Modules\Audience\Http\Controllers\ContactImportController;
use App\Modules\Audience\Http\Controllers\DevStudioSeedController;
use App\Modules\Audience\Http\Controllers\FormUiController;
use App\Modules\Audience\Http\Controllers\PublicFormController;
use App\Modules\Audience\Http\Controllers\SegmentController;
use App\Modules\Audience\Http\Controllers\TagController;
use App\Modules\Audience\Http\Middleware\HostedPageHeaders;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

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
    // v1.1 · állapotmentes: session és süti sincs (a beküldőnek nincs mit megjegyezni rólunk).
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        VerifyCsrfToken::class,
    ])
    // v1.1 · a válasz (köszönő oldal) hosztolt oldal: szigorú CSP, süti nélkül. Keretezhető,
    // mert az iframe-be ágyazott űrlap ide küld.
    ->middleware(['throttle:public-form', HostedPageHeaders::class.':framable'])
    ->where('slug', '[A-Za-z0-9\-]{1,128}')
    ->name('forms.submit');

/*
 | v1.1 · 5. modul — a HOSZTOLT űrlap-oldal (`GET /f/{slug}`): link vagy iframe.
 |
 | Állapotmentes (session, süti és CSRF nélkül, mint a megerősítő és a preference oldal):
 | a time-trap ideje az aláírt renderelési tokenből jön, nem munkamenetből. Szigorú CSP,
 | szkript nélkül; partnerenként és űrlaponként limitált.
 */
Route::get('/f/{slug}', [PublicFormController::class, 'show'])
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        VerifyCsrfToken::class,
    ])
    ->middleware(['throttle:hosted-form', HostedPageHeaders::class.':framable'])
    ->where('slug', '[A-Za-z0-9\-]{1,128}')
    ->name('forms.page');

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

    // KÖZÖNSÉG-DASHBOARD (v1.1 · 5. modul): növekedés, forrás, engagement — JSON.
    Route::get('/audience/insights', [AudienceController::class, 'insights'])
        ->middleware('throttle:audience-insights')->name('audience.insights');

    // SZEGMENSÉPÍTŐ (v1.1 · 5. modul). Az előnézet JSON (a szerkesztő debounce-szal kéri).
    Route::get('/audience/segments', [SegmentController::class, 'index'])->name('audience.segments.index');
    Route::get('/audience/segments/new', [SegmentController::class, 'create'])->name('audience.segments.create');
    Route::post('/audience/segments/preview', [SegmentController::class, 'preview'])
        ->middleware('throttle:segment-preview')->name('audience.segments.preview');
    Route::post('/audience/segments', [SegmentController::class, 'store'])->name('audience.segments.store');
    Route::get('/audience/segments/{uuid}', [SegmentController::class, 'show'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')->name('audience.segments.show');
    Route::put('/audience/segments/{uuid}', [SegmentController::class, 'update'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')->name('audience.segments.update');
    Route::delete('/audience/segments/{uuid}', [SegmentController::class, 'destroy'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')->name('audience.segments.destroy');

    // CÍMKÉK (v1.1 · 5. modul): kezelés + tömeges címkézés (kijelölés / keresés / szegmens).
    Route::get('/audience/tags', [TagController::class, 'index'])->name('audience.tags.index');
    Route::post('/audience/tags', [TagController::class, 'store'])->name('audience.tags.store');
    Route::put('/audience/tags/{uuid}', [TagController::class, 'update'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')->name('audience.tags.update');
    Route::post('/audience/tags/{uuid}/merge', [TagController::class, 'merge'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')->middleware('throttle:audience-bulk')->name('audience.tags.merge');
    Route::delete('/audience/tags/{uuid}', [TagController::class, 'destroy'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')->name('audience.tags.destroy');
    Route::post('/audience/contacts/bulk-tags', [TagController::class, 'bulk'])
        ->middleware('throttle:audience-bulk')->name('audience.contacts.bulk-tags');

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

/*
 | v1.1 · 5. modul — KIZÁRÓLAG fejlesztői/teszt-környezetben: a Studio böngésző-tesztjeinek
 | kiinduló adata (lásd `DevStudioSeedController`). Ugyanaz a kettős kapcsoló, mint a
 | dev-belépőnél: a regisztráció környezethez kötött, és a kontroller maga is ellenőriz.
 */
if (config('identity.dev_login_enabled') && app()->environment('local', 'testing')) {
    Route::get('/dev/seed-studio', DevStudioSeedController::class)
        ->middleware(['auth', 'workspace'])
        ->name('dev.seed-studio');
}
