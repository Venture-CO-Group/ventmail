<?php

declare(strict_types=1);

use App\Modules\Domains\Http\Controllers\DomainController;
use App\Modules\Domains\Http\Controllers\DomainWizardController;
use Illuminate\Support\Facades\Route;

/*
 | A modul-provider a kötelező stackkel regisztrálja (web + auth + workspace); a
 | SZEREP-ellenőrzés a kontrollerekben van (`domain.manage`).
 |
 | Minden állapotot módosító művelet POST: GET-en entitást és kulcsanyagot létrehozni
 | CSRF-védelem nélkül lehetne.
 */

Route::get('/domains/wizard', DomainWizardController::class)->name('domains.wizard');
Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
Route::post('/domains/{uuid}/verify', [DomainController::class, 'verify'])->name('domains.verify');
Route::post('/domains/{uuid}/dkim/rotate', [DomainController::class, 'rotateDkim'])->name('domains.dkim.rotate');
