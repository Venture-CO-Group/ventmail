<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\DevLoginController;
use App\Modules\Identity\Http\Controllers\DevSeedController;
use App\Modules\Identity\Http\Controllers\LocaleController;
use App\Modules\Identity\Http\Controllers\LogoutController;
use App\Modules\Identity\Http\Controllers\OidcCallbackController;
use App\Modules\Identity\Http\Controllers\OidcRedirectController;
use Illuminate\Support\Facades\Route;

/*
 | A ventSSO OIDC folyamat. A modul-provider `web` middleware-stackkel regisztrálja
 | (session kell a state/nonce/PKCE tárolásához).
 */

Route::get('/auth/redirect', OidcRedirectController::class)->name('auth.redirect');
Route::get('/auth/callback', OidcCallbackController::class)->name('auth.callback');
Route::post('/auth/logout', LogoutController::class)->name('auth.logout');

/*
 | NYELVVÁLASZTÁS.
 |
 | A választás a FELHASZNÁLÓ sorára megy, nem tenant-adat — a `workspace` middleware
 | mégis rajta van, és ez szándékos: a `ModuleRouteRegistrationTest` invariánsa az, hogy
 | modul-route KIZÁRÓLAG `auth + workspace` (vagy API-kulcs) mögött állhat. Egy „majdnem
 | ártalmatlan" kivétel itt azt jelentené, hogy a szabály többé nem szabály, hanem
 | ízlés kérdése — a következő kivételt pedig már senki nem vitatná meg.
 |
 | Gyakorlati ára nincs: tagság nélkül a felhasználó egyetlen képernyőt sem lát, tehát a
 | nyelvválasztóig sem jut el.
 */
Route::post('/locale', LocaleController::class)
    ->middleware(['auth', 'workspace'])
    ->name('locale.update');

// KIZÁRÓLAG fejlesztői/teszt környezetben: a Playwright nem tudja végigjátszani a ventSSO
// OIDC-folyamatát, a flow-tesztek viszont hitelesített munkamenetet igényelnek.
if (config('identity.dev_login_enabled') && app()->environment('local', 'testing')) {
    Route::get('/auth/dev-login', DevLoginController::class)->name('auth.dev-login');

    // A napló-flow böngésző-tesztje eddig ÜRES naplón futott, és ha nem talált sort, magától
    // átugrott — „zölden". Ez a route ad neki mit mérni.
    Route::get('/dev/seed-logs', DevSeedController::class)
        ->middleware(['auth', 'workspace'])
        ->name('dev.seed-logs');
}
