<?php

declare(strict_types=1);

use App\Modules\Domains\Http\Controllers\DomainWizardController;
use Illuminate\Support\Facades\Route;

/*
 | A modul-provider a kötelező stackkel regisztrálja (web + auth + workspace).
 */

Route::get('/domains/wizard', DomainWizardController::class)->name('domains.wizard');
