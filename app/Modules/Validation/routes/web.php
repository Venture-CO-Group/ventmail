<?php

declare(strict_types=1);

use App\Modules\Validation\Http\Controllers\ValidationUiController;
use Illuminate\Support\Facades\Route;

/*
 | A modul route-jai hitelesítés ELŐTTIEK (a `/v1` API-kulccsal hitelesít), ezért a stack
 | itt EXPLICIT: a képernyő session-hitelesítést ÉS workspace-kontextust követel. A
 | szerep-ellenőrzés (`validation.run`) a kontrollerben van.
 */
Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/validation', [ValidationUiController::class, 'index'])->name('validation.index');
    Route::post('/validation', [ValidationUiController::class, 'check'])->name('validation.check');
});
