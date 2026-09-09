<?php

declare(strict_types=1);

use App\Modules\Analytics\Http\Controllers\AnalyticsUiController;
use Illuminate\Support\Facades\Route;

/*
 | A modul route-jai hitelesítés ELŐTTIEK (a `/v1/analytics` API-kulccsal jön), ezért a
 | stack itt EXPLICIT. A szerep-ellenőrzés (`analytics.view`) a kontrollerben van.
 */
Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/analytics', [AnalyticsUiController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/campaigns/{uuid}', [AnalyticsUiController::class, 'show'])->name('analytics.campaign');
});
