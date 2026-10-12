<?php

declare(strict_types=1);

use App\Modules\Sending\Http\Controllers\PostalWebhookController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 | A Postal kézbesítési webhookja — gép-gép hívás a belső docker-hálózaton.
 |
 | Nincs session, nincs CSRF: a hívó a Postal, nem böngésző. A hitelességet a poolonkénti
 | RSA-aláírás adja (`PostalWebhookController`), az `/internal/*` útvonalat az edge Caddy
 | kívülről nem is engedi. Rate-limit van rajta.
 */

Route::post('/internal/mta/postal/{pool}', PostalWebhookController::class)
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        VerifyCsrfToken::class,
    ])
    ->middleware('throttle:postal-webhook')
    ->where('pool', '[a-z]+')
    ->name('sending.postal.webhook');
