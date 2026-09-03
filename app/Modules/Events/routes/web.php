<?php

declare(strict_types=1);

use App\Modules\Events\Http\Controllers\TrackingController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 | NYOMKÖVETÉS — nyitás és kattintás (23., 26. kritérium).
 |
 | Ezeket a LEVÉLKLIENS hívja, nem a bejelentkezett felhasználó: hitelesítés itt nem
 | értelmezett. A hozzáférést maga a TOKEN adja, ami titkosított, tehát hamisíthatatlan és
 | nem olvasható — a tenant-kontextus is belőle származik.
 |
 | Rate-limit viszont KELL (golden rule: publikus endpoint sosem limit nélkül): a végpont
 | nyilvános, és korlátozás nélkül az eseménytábla hizlalására lenne használható.
 |
 | ÁLLAPOTMENTES: a session- és cookie-middleware KIVÉVE. A pixelt a címzett levélkliense (és
 | a Gmail képproxyja) tölti be — session-sütit tenni a levelébe semmilyen jogalap nem fedez,
 | és minden betöltés írna egy Redis-session-rekordot is.
 */

$stateless = [
    StartSession::class,
    ShareErrorsFromSession::class,
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    // A CSRF-őr a session-ből olvasna: session nélkül maga dobna kivételt. GET-en amúgy
    // sincs dolga.
    VerifyCsrfToken::class,
];

Route::get('/t/o/{token}', [TrackingController::class, 'open'])
    ->withoutMiddleware($stateless)
    ->middleware('throttle:tracking')
    ->where('token', '[A-Za-z0-9\-_~]+')
    ->name('tracking.open');

Route::get('/t/c/{token}', [TrackingController::class, 'click'])
    ->withoutMiddleware($stateless)
    ->middleware('throttle:tracking')
    ->where('token', '[A-Za-z0-9\-_~]+')
    ->name('tracking.click');
