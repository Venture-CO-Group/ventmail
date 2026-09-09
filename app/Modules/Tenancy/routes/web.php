<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\DashboardController;
use App\Modules\Tenancy\Http\Controllers\MemberController;
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

/*
 | TAGOK, MEGHÍVÓK, SUB-ACCOUNTOK.
 |
 | A `member.manage` / `subAccount.manage` kapuzás a CONTROLLERBEN van, nem itt: a
 | képernyő több művelete külön képességet kér (a sub-account létrehozása mást, mint a
 | szerep-kiosztás), és egy route-szintű, mindenre egyforma kapu ezt elmosná.
 */
Route::get('/members', [MemberController::class, 'index'])->name('members.index');
Route::post('/members/invitations', [MemberController::class, 'invite'])->name('members.invite');
Route::delete('/members/invitations/{uuid}', [MemberController::class, 'revokeInvitation'])
    ->where('uuid', '[0-9a-fA-F\-]{36}')->name('members.invitations.revoke');
Route::post('/members/{member}/roles', [MemberController::class, 'assignRoles'])
    ->where('member', '[0-9]+')->name('members.roles');
Route::delete('/members/{member}', [MemberController::class, 'remove'])
    ->where('member', '[0-9]+')->name('members.remove');
Route::post('/sub-accounts', [MemberController::class, 'storeSubAccount'])->name('subAccounts.store');
