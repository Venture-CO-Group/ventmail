<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\DevLoginController;
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

// KIZÁRÓLAG fejlesztői/teszt környezetben: a Playwright nem tudja végigjátszani a ventSSO
// OIDC-folyamatát, a flow-tesztek viszont hitelesített munkamenetet igényelnek.
if (app()->environment('local', 'testing')) {
    Route::get('/auth/dev-login', DevLoginController::class)->name('auth.dev-login');
}
