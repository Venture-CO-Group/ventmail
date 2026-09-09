<?php

declare(strict_types=1);

use App\Modules\Suppression\Http\Controllers\SuppressionUiController;
use App\Modules\Suppression\Http\Controllers\UnsubscribeController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 | ONE-CLICK LEIRATKOZÁS (RFC 8058).
 |
 | Ezt a LEVÉLKLIENS hívja (Gmail, Yahoo, Apple Mail), nem a bejelentkezett böngésző:
 | hitelesítés itt nem értelmezett, a hozzáférést a titkosított token adja.
 |
 | ÁLLAPOTMENTES, és a CSRF-őr KIVÉVE: a szabvány szerint a levélkliens POST-ol, session és
 | CSRF-token nélkül. Aki ezt a védelmet itt bent hagyja, az a leiratkozást teszi
 | lehetetlenné — és a leiratkozás helyett a spam-gomb következik.
 */

$stateless = [
    StartSession::class,
    ShareErrorsFromSession::class,
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    VerifyCsrfToken::class,
];

Route::post('/u/{token}', [UnsubscribeController::class, 'post'])
    ->withoutMiddleware($stateless)
    ->middleware('throttle:unsubscribe')
    ->where('token', '[A-Za-z0-9\-_~]{1,4096}')
    ->name('unsubscribe.post');

Route::get('/u/{token}', [UnsubscribeController::class, 'get'])
    ->withoutMiddleware($stateless)
    ->middleware('throttle:unsubscribe')
    ->where('token', '[A-Za-z0-9\-_~]{1,4096}')
    ->name('unsubscribe.get');

/*
 | A TILTÓLISTA FELÜLETE — a fentiek ELLENTÉTE.
 |
 | A leiratkozó végpontokat a levélkliens hívja, session nélkül; ez a képernyő viszont a
 | bejelentkezett üzemeltetőé. A modul route-jai hitelesítés ELŐTTIEK
 | (`routesPrecedeAuthentication`), ezért a stack itt EXPLICIT — a
 | `ModuleRouteRegistrationTest` invariánsa (`auth` + `workspace`) enélkül sérülne, és a
 | tiltólista bejelentkezés nélkül lenne olvasható.
 */
Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/suppressions', [SuppressionUiController::class, 'index'])->name('suppressions.index');
    Route::post('/suppressions', [SuppressionUiController::class, 'store'])->name('suppressions.store');
    Route::delete('/suppressions', [SuppressionUiController::class, 'destroy'])->name('suppressions.destroy');
});
