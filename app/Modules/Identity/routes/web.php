<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\OidcCallbackController;
use App\Modules\Identity\Http\Controllers\OidcRedirectController;
use Illuminate\Support\Facades\Route;

/*
 | A ventSSO OIDC folyamat. A modul-provider `web` middleware-stackkel regisztrálja
 | (session kell a state/nonce/PKCE tárolásához).
 */

Route::get('/auth/redirect', OidcRedirectController::class)->name('auth.redirect');
Route::get('/auth/callback', OidcCallbackController::class)->name('auth.callback');
