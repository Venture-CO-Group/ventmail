<?php

declare(strict_types=1);

use App\Modules\SuperAdmin\Http\Controllers\PlatformConsoleController;
use Illuminate\Support\Facades\Route;

/*
 | A PLATFORM-KONZOL (81–88. kritérium).
 |
 | A modul-provider a kötelező stackkel regisztrálja (web + auth + workspace). A
 | `workspace` middleware itt is ott van, és ez SZÁNDÉKOS: a `ModuleRouteRegistrationTest`
 | invariánsa nem ismer kivételt, és a platform-adminnak amúgy is van kontextusa (vagy a
 | saját tagsága, vagy — aktív támogatói nézet alatt — a megfigyelt tenant).
 |
 | A JOGOSULTSÁGOT nem a route-stack adja, hanem a `platform.admin` képesség, amit a
 | kontroller minden műveletnél EXPLICIT ellenőriz — a felhasználót továbbadva, hogy a
 | kapu és az audit ugyanarról az emberről szóljon.
 |
 | A `platform.impersonation.end` név nem díszítés: a `EnforceImpersonation` middleware
 | EZT az egy útvonalat engedi át aktív nézet alatt — enélkül a támogatói nézetből nem
 | lehetne kilépni, csak megvárni a 30 perces lejáratot.
 */
Route::get('/platform', [PlatformConsoleController::class, 'index'])->name('platform.index');
// v1.1 · 7. modul: a küldési infrastruktúra (poolok, IP-reputáció, blocklist, rate limitek) — csak olvasás.
Route::get('/platform/infrastructure', [PlatformConsoleController::class, 'infrastructure'])->name('platform.infrastructure');
Route::get('/platform/audit', [PlatformConsoleController::class, 'audit'])->name('platform.audit');

Route::post('/platform/impersonation', [PlatformConsoleController::class, 'startImpersonation'])
    ->name('platform.impersonation.start');
Route::post('/platform/impersonation/end', [PlatformConsoleController::class, 'endImpersonation'])
    ->name('platform.impersonation.end');

Route::post('/platform/overrides', [PlatformConsoleController::class, 'grantOverride'])->name('platform.overrides.grant');
Route::delete('/platform/overrides/{uuid}', [PlatformConsoleController::class, 'revokeOverride'])->name('platform.overrides.revoke');

Route::post('/platform/suppressions', [PlatformConsoleController::class, 'addSuppression'])->name('platform.suppressions.add');
Route::delete('/platform/suppressions/{uuid}', [PlatformConsoleController::class, 'removeSuppression'])
    ->name('platform.suppressions.remove');
