<?php

declare(strict_types=1);

use App\Modules\Journey\Http\Controllers\JourneyUiController;
use Illuminate\Support\Facades\Route;

/*
 | A modul route-jai hitelesítés ELŐTTIEK (a `/v1/journeys/events` API-kulccsal jön),
 | ezért a stack itt EXPLICIT: a journey-felület session-hitelesítést és
 | workspace-kontextust követel. A szerep-ellenőrzés a kontrollerben van — a szerkesztés
 | `campaign.create`, az AKTIVÁLÁS `campaign.publish`, mert az küldést indít.
 */
Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/journeys', [JourneyUiController::class, 'index'])->name('journeys.index');
    Route::post('/journeys', [JourneyUiController::class, 'store'])->name('journeys.store');
    Route::get('/journeys/{uuid}', [JourneyUiController::class, 'show'])->name('journeys.show');
    Route::post('/journeys/{uuid}/activate', [JourneyUiController::class, 'activate'])->name('journeys.activate');
    Route::post('/journeys/{uuid}/pause', [JourneyUiController::class, 'pause'])->name('journeys.pause');
});
