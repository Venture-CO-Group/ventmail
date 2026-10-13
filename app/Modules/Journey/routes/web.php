<?php

declare(strict_types=1);

use App\Modules\Journey\Http\Controllers\JourneyCanvasController;
use App\Modules\Journey\Http\Controllers\JourneyUiController;
use Illuminate\Support\Facades\Route;

/*
 | A modul route-jai hitelesítés ELŐTTIEK (a `/v1/journeys/events` API-kulccsal jön),
 | ezért a stack itt EXPLICIT: a journey-felület session-hitelesítést és
 | workspace-kontextust követel. A szerep-ellenőrzés a kontrollerben van — a szerkesztés
 | `campaign.create`, a PUBLIKÁLÁS / SZÜNETELTETÉS `journey.publish`, mert az küldést indít.
 */
Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/journeys', [JourneyUiController::class, 'index'])->name('journeys.index');
    Route::post('/journeys', [JourneyUiController::class, 'store'])->name('journeys.store');
    Route::get('/journeys/{uuid}', [JourneyUiController::class, 'show'])->whereUuid('uuid')->name('journeys.show');
    Route::post('/journeys/{uuid}/activate', [JourneyUiController::class, 'activate'])->whereUuid('uuid')->name('journeys.activate');

    // v1.1 · 6. modul — a vászon JSON-végpontjai.
    Route::middleware('throttle:journey-editor')->group(function (): void {
        Route::put('/journeys/{uuid}/graph', [JourneyCanvasController::class, 'save'])->whereUuid('uuid')->name('journeys.graph.save');
        Route::post('/journeys/{uuid}/validate', [JourneyCanvasController::class, 'validateGraph'])->whereUuid('uuid')->name('journeys.validate');
        Route::post('/journeys/{uuid}/publish', [JourneyCanvasController::class, 'publish'])->whereUuid('uuid')->name('journeys.publish');
        Route::post('/journeys/{uuid}/pause', [JourneyCanvasController::class, 'pause'])->whereUuid('uuid')->name('journeys.pause');
    });
});
