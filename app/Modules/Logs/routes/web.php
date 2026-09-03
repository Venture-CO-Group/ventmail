<?php

declare(strict_types=1);

use App\Modules\Logs\Http\Controllers\LogController;
use Illuminate\Support\Facades\Route;

/*
 | A napló és az üzenet-trace felülete. A modul-provider adja a kötelező stacket
 | (web + auth + workspace), tehát idegen tenant üzenete nem is látszik.
 */

Route::get('/logs', [LogController::class, 'index'])->name('logs.index');
Route::get('/logs/{uuid}', [LogController::class, 'show'])->name('logs.show');
