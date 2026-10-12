<?php

declare(strict_types=1);

use App\Modules\Audience\Http\Middleware\HostedPageHeaders;
use App\Modules\Consent\Http\Controllers\ConfirmSubscriptionController;
use App\Modules\Consent\Http\Controllers\PreferenceCenterController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 | DOUBLE OPT-IN MEGERŐSÍTÉS és PREFERENCE CENTER (50., 55. kritérium).
 |
 | Mindkettőt a LEVÉLBŐL nyitja meg a címzett — nem a bejelentkezett böngésző. Hitelesítés
 | itt nem értelmezett: a hozzáférést a titkosított, cél-elválasztott, LEJÁRÓ token adja, és
 | a tenant-kontextus is belőle jön.
 |
 | ÁLLAPOTMENTES, CSRF-őr nélkül: a levélkliensből érkező kérésnek nincs session-je. A
 | preference center POST-ja ezért nem CSRF-védett — a védelem az, hogy a tokent birtokolni
 | kell hozzá, és hogy a végpont hatása a címzett SAJÁT beállításaira korlátozódik.
 */

$stateful = [
    StartSession::class,
    ShareErrorsFromSession::class,
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    VerifyCsrfToken::class,
];

// A GET NEM ÍR (megerősítő oldal), a POST ír. Ugyanaz a döntés, mint a `/u/` végponton:
// a levélben lévő linkeket a védelmi átjárók (SafeLinks, vírusszűrő proxy) végiglátogatják,
// és írós GET-tel egy SZKENNER teremtett volna jogalapot — a bizonyítékba az ő IP-jével.
Route::get('/c/{token}', [ConfirmSubscriptionController::class, 'show'])
    ->withoutMiddleware($stateful)
    ->middleware([HostedPageHeaders::class, 'throttle:consent-confirm'])
    ->where('token', '[A-Za-z0-9\-_~]{1,4096}')
    ->name('consent.confirm');

Route::post('/c/{token}', [ConfirmSubscriptionController::class, 'store'])
    ->withoutMiddleware($stateful)
    ->middleware([HostedPageHeaders::class, 'throttle:consent-confirm'])
    ->where('token', '[A-Za-z0-9\-_~]{1,4096}')
    ->name('consent.confirm.store');

Route::get('/p/{token}', [PreferenceCenterController::class, 'show'])
    ->withoutMiddleware($stateful)
    ->middleware([HostedPageHeaders::class, 'throttle:preferences'])
    ->where('token', '[A-Za-z0-9\-_~]{1,4096}')
    ->name('preferences.show');

Route::post('/p/{token}', [PreferenceCenterController::class, 'update'])
    ->withoutMiddleware($stateful)
    ->middleware([HostedPageHeaders::class, 'throttle:preferences'])
    ->where('token', '[A-Za-z0-9\-_~]{1,4096}')
    ->name('preferences.update');
