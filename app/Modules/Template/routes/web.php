<?php

declare(strict_types=1);

use App\Modules\Template\Http\Controllers\SavedBlockController;
use App\Modules\Template\Http\Controllers\TemplateAssetController;
use App\Modules\Template\Http\Controllers\TemplateEditorController;
use App\Modules\Template\Http\Controllers\TemplateUiController;
use App\Modules\Template\Http\Controllers\TemplateVersionController;
use App\Modules\Template\Http\Controllers\WebVersionController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 | „MEGNYITÁS BÖNGÉSZŐBEN" (v1.1 · `{{ browser_url }}`).
 |
 | A LEVÉL OLVASÓJA hívja, nem a bejelentkezett felhasználó: hitelesítés itt nem
 | értelmezett, a hozzáférést a titkosított, cél-elválasztott token adja (a tenant-kontextus
 | is belőle jön). ÁLLAPOTMENTES (session, süti, CSRF nélkül), rate-limittel. A válasz
 | `default-src 'none'` CSP-vel megy — lásd `WebVersionController`.
 */
Route::get('/w/{token}', [WebVersionController::class, 'show'])
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        VerifyCsrfToken::class,
    ])
    ->middleware('throttle:web-version')
    ->where('token', '[A-Za-z0-9\-_~]{1,4096}')
    ->name('templates.web-version');

/*
 | A SABLON-FELÜLET — a bejelentkezett felhasználóé. A modul route-jai hitelesítés ELŐTTIEK
 | (`routesPrecedeAuthentication`, a webes nézet miatt), ezért a stack itt EXPLICIT: `auth`
 | + `workspace`. A szerep-ellenőrzés a kontrollerben van (`template.create`).
 */
Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/templates', [TemplateUiController::class, 'index'])->name('templates.index');
    Route::post('/templates', [TemplateUiController::class, 'store'])->name('templates.store');
    Route::get('/templates/{uuid}', [TemplateUiController::class, 'show'])->whereUuid('uuid')->name('templates.show');
    Route::put('/templates/{uuid}', [TemplateUiController::class, 'update'])->whereUuid('uuid')->name('templates.update');
    Route::delete('/templates/{uuid}', [TemplateUiController::class, 'destroy'])->whereUuid('uuid')->name('templates.destroy');

    /*
     | v1.1 · a szerkesztő JSON-végpontjai. Az autosave és az előnézet gyakori: a throttle a
     | felhasználóé (nem a tenanté), és bőven a debounce fölött van.
     */
    Route::middleware('throttle:template-editor')->group(function (): void {
        Route::put('/templates/{uuid}/autosave', [TemplateEditorController::class, 'autosave'])->whereUuid('uuid')->name('templates.autosave');
        Route::post('/templates/{uuid}/preview', [TemplateEditorController::class, 'preview'])->whereUuid('uuid')->name('templates.preview');
        Route::post('/templates/{uuid}/optimize', [TemplateEditorController::class, 'optimize'])->whereUuid('uuid')->name('templates.optimize');
    });

    Route::post('/templates/{uuid}/copy', [TemplateEditorController::class, 'copy'])->whereUuid('uuid')->name('templates.copy');
    Route::get('/templates/{uuid}/versions', [TemplateVersionController::class, 'index'])->whereUuid('uuid')->name('templates.versions');
    Route::post('/templates/{uuid}/versions/{number}/restore', [TemplateVersionController::class, 'restore'])
        ->whereUuid('uuid')->whereNumber('number')->name('templates.versions.restore');

    // TESZT-KÜLDÉS: küldő POST → Idempotency-Key KÖTELEZŐ (golden rule 5), és szűk throttle.
    Route::post('/templates/{uuid}/test-send', [TemplateEditorController::class, 'testSend'])
        ->whereUuid('uuid')
        ->middleware(['api.idempotency', 'throttle:template-test-send'])
        ->name('templates.test-send');

    Route::get('/template-assets', [TemplateAssetController::class, 'index'])->name('template-assets.index');
    Route::post('/template-assets', [TemplateAssetController::class, 'store'])->middleware('throttle:template-assets')->name('template-assets.store');
    Route::delete('/template-assets/{uuid}', [TemplateAssetController::class, 'destroy'])->whereUuid('uuid')->name('template-assets.destroy');

    Route::post('/template-blocks', [SavedBlockController::class, 'store'])->middleware('throttle:template-editor')->name('template-blocks.store');
    Route::delete('/template-blocks/{uuid}', [SavedBlockController::class, 'destroy'])->whereUuid('uuid')->name('template-blocks.destroy');
});
