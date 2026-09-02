<?php

declare(strict_types=1);

use App\Modules\StoredTemplates\Http\Controllers\StoredTemplateController;
use Illuminate\Support\Facades\Route;

/*
 | Tárolt sablonok (spec 15.). Az írás `templates:write`, az olvasás `templates:read` scope.
 */

Route::get('/templates', [StoredTemplateController::class, 'index'])
    ->middleware('api.scope:templates:read')->name('v1.templates.index');

Route::post('/templates', [StoredTemplateController::class, 'store'])
    ->middleware('api.scope:templates:write')->name('v1.templates.store');

Route::post('/templates/{name}/versions', [StoredTemplateController::class, 'storeVersion'])
    ->middleware('api.scope:templates:write')->name('v1.templates.versions.store');
