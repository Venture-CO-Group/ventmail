<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\DashboardController;
use App\Modules\Tenancy\Http\Controllers\WorkspaceSwitchController;
use Illuminate\Support\Facades\Route;

/*
 | Az APP-HÉJ útvonalai: a nyitóképernyő és a workspace-váltó.
 |
 | A kötelező middleware-stacket (`web + auth + workspace`) a `ModuleServiceProvider` adja
 | — a modul csak a prefixet és a nevet választja meg.
 */

Route::get('/dashboard', DashboardController::class)->name('dashboard');

// A VÁLTÁS POST: egy GET-tel váltó link előre-hátra gombbal és link-előtöltéssel is
// elsülne, és a felhasználó azt hinné, a másik tenant adatait látja, miközben nem.
Route::post('/workspace/switch', WorkspaceSwitchController::class)->name('workspace.switch');
