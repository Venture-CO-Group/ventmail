<?php

declare(strict_types=1);

use App\Http\Controllers\RobotsController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Inertia;

/*
 | A publikus /v1 felület az Api modul gazdája (A1). Itt csak az app-héj útvonalai élnek.
 */

// Hostonként más robots.txt (landing: engedett; app, api: tiltott). A `public/robots.txt`
// SZÁNDÉKOSAN nincs: egy statikus fájl minden hostra ugyanazt adná.
/*
 | A nyilvános (landing, robots) válasz NEM indít munkamenetet és nem állít sütit: nincs mit
 | megjegyeznie a látogatóról, és a süti-mentesség a consent-banner igényét is kiváltja.
 */
$stateless = [
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
    AddQueuedCookiesToResponse::class,
    EncryptCookies::class,
    HandleInertiaRequests::class,
];

Route::get('/robots.txt', RobotsController::class)->withoutMiddleware($stateless)->name('robots');

/*
 | A STATIKUS TERMÉKOLDAL az apex hoston. Az app-útvonal ELŐTT regisztrálódik, hogy a `/`
 | az apexen a landinget adja — az app `/`-je (belépő) a többi hoston él. Nincs Inertia és
 | nincs app-JS: egyetlen Blade-nézet + egy CSS-belépő.
 */
Route::domain((string) config('app.landing_host'))->withoutMiddleware($stateless)->group(function (): void {
    Route::view('/', 'landing')->name('landing');
});

// A BELÉPŐ: vendégnek a ventSSO-gomb, bejelentkezettnek irány a dashboard.
Route::get('/', function (Request $request) {
    // Az API hoston a `/` nem a belépő.
    abort_if(strtolower($request->getHost()) === strtolower((string) config('app.api_host')), 404);

    if ($request->user() !== null) {
        return redirect('/dashboard');
    }

    return Inertia::render('Auth/Login');
})->name('home');
