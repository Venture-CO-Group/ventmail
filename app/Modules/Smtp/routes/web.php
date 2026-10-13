<?php

declare(strict_types=1);

use App\Modules\Smtp\Http\Controllers\SmtpCredentialController;
use Illuminate\Support\Facades\Route;

/*
 | A modul-provider a kötelező stackkel regisztrálja (web + auth + workspace); a
 | szerep-ellenőrzés a kontrollerben van (`smtpCredential.manage`).
 */

Route::get('/smtp-credentials', [SmtpCredentialController::class, 'index'])->name('smtp.index');
Route::post('/smtp-credentials', [SmtpCredentialController::class, 'store'])->name('smtp.store');
Route::post('/smtp-credentials/{uuid}/rotate', [SmtpCredentialController::class, 'rotate'])->name('smtp.rotate');
Route::delete('/smtp-credentials/{uuid}', [SmtpCredentialController::class, 'destroy'])->name('smtp.destroy');
