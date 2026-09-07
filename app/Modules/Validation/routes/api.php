<?php

declare(strict_types=1);

use App\Modules\Validation\Http\Controllers\ValidationController;
use Illuminate\Support\Facades\Route;

/*
 | A5 — címellenőrzés. A `validate:run` scope külön értékesíthető szolgáltatás (spec 12).
 */

Route::post('/validate', [ValidationController::class, 'validateOne'])
    ->middleware('api.scope:validate:run')
    ->name('v1.validate');

Route::post('/validate/bulk', [ValidationController::class, 'bulk'])
    ->middleware('api.scope:validate:run')
    ->name('v1.validate.bulk');

Route::get('/validate/bulk/{uuid}', [ValidationController::class, 'show'])
    ->middleware('api.scope:validate:run')
    ->name('v1.validate.bulk.show');

Route::get('/validate/bulk/{uuid}/export', [ValidationController::class, 'export'])
    ->middleware('api.scope:validate:run')
    ->name('v1.validate.bulk.export');
