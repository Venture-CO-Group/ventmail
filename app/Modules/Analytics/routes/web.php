<?php

declare(strict_types=1);

use App\Modules\Analytics\Http\Controllers\AnalyticsUiController;
use App\Modules\Analytics\Http\Controllers\BrandingController;
use App\Modules\Analytics\Http\Controllers\DevSeedAnalyticsController;
use App\Modules\Analytics\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
 | A modul route-jai hitelesítés ELŐTTIEK (a `/v1/analytics` API-kulccsal jön), ezért a
 | stack itt EXPLICIT. A szerep-ellenőrzés (`analytics.view`, `workspace.manage`) a kontrollerben van.
 */
Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/analytics', [AnalyticsUiController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/campaigns/{uuid}', [AnalyticsUiController::class, 'show'])->name('analytics.campaign');
    Route::get('/analytics/campaigns/{uuid}/heatmap', [AnalyticsUiController::class, 'heatmap'])
        ->where('uuid', '[0-9a-fA-F-]{36}')
        ->name('analytics.heatmap');
    Route::get('/analytics/campaigns/{uuid}/links.csv', [AnalyticsUiController::class, 'linksCsv'])
        ->where('uuid', '[0-9a-fA-F-]{36}')
        ->name('analytics.links-csv');

    // v1.1 · 8. modul — BRANDELT PDF-RIPORT (háttérfeladat, aláírt letöltés).
    Route::post('/analytics/reports', [ReportController::class, 'store'])
        ->middleware('throttle:analytics-report')
        ->name('analytics.reports.store');
    Route::get('/analytics/reports', [ReportController::class, 'index'])->name('analytics.reports.index');
    Route::get('/analytics/reports/{uuid}/download', [ReportController::class, 'download'])
        ->middleware('signed')
        ->where('uuid', '[0-9a-fA-F-]{36}')
        ->name('analytics.reports.download');

    // v1.1 · 8. modul — WORKSPACE-BRANDING (Settings ▾ Branding).
    Route::get('/settings/branding', [BrandingController::class, 'show'])->name('branding.show');
    Route::put('/settings/branding', [BrandingController::class, 'update'])->name('branding.update');
    Route::get('/settings/branding/logo', [BrandingController::class, 'logo'])->name('branding.logo');
    Route::post('/settings/branding/logo', [BrandingController::class, 'uploadLogo'])
        ->middleware('throttle:branding-logo')
        ->name('branding.logo.upload');
    Route::delete('/settings/branding/logo', [BrandingController::class, 'removeLogo'])->name('branding.logo.remove');
});

// KIZÁRÓLAG fejlesztői/teszt környezetben (a `/dev/seed-logs` mintája): demó-adat az analitika
// böngésző-tesztjéhez. Éles boot a route jelenlétére leáll (AppServiceProvider::DEVELOPMENT_ROUTES).
if (config('identity.dev_login_enabled') && app()->environment('local', 'testing')) {
    Route::get('/dev/seed-analytics', DevSeedAnalyticsController::class)
        ->middleware(['auth', 'workspace'])
        ->name('dev.seed-analytics');
}
