<?php

declare(strict_types=1);

use App\Modules\Webhooks\Http\Controllers\WebhookUiController;
use Illuminate\Support\Facades\Route;

/*
 | A WEBHOOK-FELÜLET. A modul-provider a kötelező stackkel regisztrálja
 | (web + auth + workspace); a szerep-ellenőrzés a kontrollerben van (`webhook.manage`).
 |
 | A gépi felület (`/v1/webhooks/*`) az `api.php`-ben él, API-kulccsal — ugyanazokat a
 | modelleket és ugyanazt az állapot-átmenetet használja.
 */

/*
 | A STACK ITT EXPLICIT.
 |
 | A modul `routesPrecedeAuthentication = true` (a `/v1` felülete API-kulccsal hitelesít,
 | nem session-nel), tehát a `web.php` route-jai alapból CSAK `web`-et kapnának. Mérve: a
 | felület enélkül 403-at adott (nincs user → nincs képesség), és a
 | `ModuleRouteRegistrationTest` is jogosan bukott volna — egy hitelesítés nélküli
 | webhook-képernyő a tenant végpontjait és a kézbesítési naplóját adná ki.
 */
Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/webhooks', [WebhookUiController::class, 'index'])->name('webhooks.index');
    Route::post('/webhooks', [WebhookUiController::class, 'store'])->name('webhooks.store');
    Route::patch('/webhooks/{uuid}', [WebhookUiController::class, 'update'])->name('webhooks.update');
    Route::delete('/webhooks/{uuid}', [WebhookUiController::class, 'destroy'])->name('webhooks.destroy');
    // v1.1 · 7. modul: titok-rotáció (türelmi ablakkal) és teszt-esemény.
    Route::post('/webhooks/{uuid}/rotate-secret', [WebhookUiController::class, 'rotate'])->name('webhooks.rotate');
    Route::post('/webhooks/{uuid}/test', [WebhookUiController::class, 'test'])->name('webhooks.test');
    Route::post('/webhooks/deliveries/{uuid}/replay', [WebhookUiController::class, 'replay'])->name('webhooks.replay');
});
