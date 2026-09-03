<?php

declare(strict_types=1);

use App\Modules\Events\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

/*
 | NYOMKÖVETÉS — nyitás és kattintás (23., 26. kritérium).
 |
 | Ezeket a LEVÉLKLIENS hívja, nem a bejelentkezett felhasználó: hitelesítés itt nem
 | értelmezett. A hozzáférést maga a TOKEN adja, ami titkosított, tehát hamisíthatatlan és
 | nem olvasható — a tenant-kontextus is belőle származik.
 |
 | Rate-limit viszont KELL (golden rule: publikus endpoint sosem limit nélkül): a végpont
 | nyilvános, és korlátozás nélkül az eseménytábla hizlalására lenne használható.
 */

Route::get('/t/o/{token}', [TrackingController::class, 'open'])
    ->middleware('throttle:tracking')
    ->where('token', '[A-Za-z0-9\-_~]+')
    ->name('tracking.open');

Route::get('/t/c/{token}', [TrackingController::class, 'click'])
    ->middleware('throttle:tracking')
    ->where('token', '[A-Za-z0-9\-_~]+')
    ->name('tracking.click');
