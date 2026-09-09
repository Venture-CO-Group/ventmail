<?php

declare(strict_types=1);

use App\Modules\Template\Http\Controllers\TemplateUiController;
use Illuminate\Support\Facades\Route;

/*
 | A modul-provider a kötelező stackkel regisztrálja (web + auth + workspace); a
 | szerep-ellenőrzés a kontrollerben van (`template.create`).
 */
Route::get('/templates', [TemplateUiController::class, 'index'])->name('templates.index');
Route::post('/templates', [TemplateUiController::class, 'store'])->name('templates.store');
Route::get('/templates/{uuid}', [TemplateUiController::class, 'show'])->name('templates.show');
Route::put('/templates/{uuid}', [TemplateUiController::class, 'update'])->name('templates.update');
