<?php

declare(strict_types=1);

use App\Http\Controllers\RobotsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 | A publikus /v1 felület az Api modul gazdája (A1). Itt csak az app-héj útvonalai élnek.
 */

// Hostonként más robots.txt (landing: engedett; app, api: tiltott). A `public/robots.txt`
// SZÁNDÉKOSAN nincs: egy statikus fájl minden hostra ugyanazt adná.
Route::get('/robots.txt', RobotsController::class)->name('robots');

/*
 | A STATIKUS TERMÉKOLDAL az apex hoston. Az app-útvonal ELŐTT regisztrálódik, hogy a `/`
 | az apexen a landinget adja — az app `/`-je (belépő) a többi hoston él. Nincs Inertia és
 | nincs app-JS: egyetlen Blade-nézet + egy CSS-belépő.
 */
Route::domain((string) config('app.landing_host'))->group(function (): void {
    Route::view('/', 'landing')->name('landing');
});

// A BELÉPŐ: vendégnek a ventSSO-gomb, bejelentkezettnek irány a dashboard.
Route::get('/', function (Request $request) {
    if ($request->user() !== null) {
        return redirect('/dashboard');
    }

    return Inertia::render('Auth/Login', ['host' => $request->getHost()]);
})->name('home');
