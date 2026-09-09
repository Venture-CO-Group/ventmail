<?php

declare(strict_types=1);

use App\Modules\Deliverability\Http\Controllers\DeliverabilityController;
use App\Modules\Deliverability\Http\Controllers\MtaStsController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 | MTA-STS POLICY (RFC 8461, 48. kritérium).
 |
 | Ezt egy IDEGEN MTA hívja meg, mielőtt levelet küldene nekünk — nem a bejelentkezett
 | böngésző. Hitelesítés itt nem értelmezett: a hitelesség forrása a TANÚSÍTVÁNY, a
 | hozzáférés-vezérlés pedig az, hogy csak verifikált domainre adunk ki policy-t.
 |
 | ÁLLAPOTMENTES: session és sütik nélkül. Egy statikus szövegfájlra session-cookie-t tenni
 | fölösleges, és minden idegen MTA-nak új session-sort nyitna.
 */

$stateful = [
    StartSession::class,
    ShareErrorsFromSession::class,
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    VerifyCsrfToken::class,
];

Route::get('/.well-known/mta-sts.txt', MtaStsController::class)
    ->withoutMiddleware($stateful)
    ->middleware('throttle:mta-sts')
    ->name('mta-sts.policy');

/*
 | A KÉZBESÍTHETŐSÉGI KÉPERNYŐ — a fentiek ellentéte.
 |
 | Az MTA-STS policy-t idegen MTA kéri le, session nélkül; ez a képernyő a bejelentkezett
 | üzemeltetőé. A modul route-jai hitelesítés ELŐTTIEK, ezért a stack itt EXPLICIT — a
 | `ModuleRouteRegistrationTest` invariánsa (`auth` + `workspace`) enélkül sérülne, és a
 | blocklist- meg DMARC-adatok bejelentkezés nélkül lennének olvashatók.
 */
Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/deliverability', DeliverabilityController::class)->name('deliverability.index');
});
