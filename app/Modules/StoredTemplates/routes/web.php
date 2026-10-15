<?php

declare(strict_types=1);

use App\Modules\StoredTemplates\Http\Controllers\StoredTemplateUiController;
use Illuminate\Support\Facades\Route;

/*
 | TÁROLT SABLONOK felülete. A modul route-jai hitelesítés előttiek (az `api.php` az
 | API-kulcsos úté), ezért a webes stack itt EXPLICIT.
 */
Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/stored-templates', [StoredTemplateUiController::class, 'index'])->name('storedTemplates.index');
    Route::post('/stored-templates/{uuid}/versions/{version}/activate', [StoredTemplateUiController::class, 'activate'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')
        ->where('version', '[0-9]+')
        ->name('storedTemplates.activate');
    Route::post('/stored-templates/{uuid}/versions', [StoredTemplateUiController::class, 'storeVersion'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')
        ->name('storedTemplates.storeVersion');
});
