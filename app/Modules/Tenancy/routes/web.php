<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\DashboardController;
use App\Modules\Tenancy\Http\Controllers\InvitationController;
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
// A MEGHÍVÓ levelet küld egy idegen címre a mi hitelesített domainünkről, az al-fiók
// pedig workspace-t hoz létre a parent számlájára — mindkettő plafonos.
Route::post('/members/invitations', [MemberController::class, 'invite'])
    ->middleware('throttle:member-invites')->name('members.invite');
Route::delete('/members/invitations/{uuid}', [MemberController::class, 'revokeInvitation'])
    ->where('uuid', '[0-9a-fA-F\-]{36}')->name('members.invitations.revoke');
// A SZEREP-KIOSZTÁS is plafonos: a tulajdonosi bit ki-be kapcsolgatása al-fiókonként
// audit-sort ír, egy append-only táblába, aminek 365 napos alsó időhatára van — a
// fölösleges sor egy évig velünk marad.
Route::post('/members/{member}/roles', [MemberController::class, 'assignRoles'])
    ->middleware('throttle:member-roles')
    ->where('member', '[0-9]+')->name('members.roles');
Route::delete('/members/{member}', [MemberController::class, 'remove'])
    ->where('member', '[0-9]+')->name('members.remove');
Route::post('/sub-accounts', [MemberController::class, 'storeSubAccount'])
    ->middleware('throttle:sub-accounts')->name('subAccounts.store');

/*
 | A MEGHÍVOTT SAJÁT képernyője — a `workspace` middleware NÉLKÜL.
 |
 | Aki ide jön, jellemzően még nem tagja egyetlen workspace-nek sem: a `ResolveWorkspace`
 | tagság híján (helyesen) 403-at adna, és a meghívott a saját meghívóját sem látná. A
 | hitelesítés viszont KÖTELEZŐ marad — a meghívó a belépett felhasználó VERIFIKÁLT
 | címéhez van kötve, és a hozzárendelést a `RedeemInvitations` ellenőrzi újra.
 */
Route::withoutMiddleware('workspace')->group(function (): void {
    Route::get('/invitations', [InvitationController::class, 'index'])->name('invitations.index');
    Route::post('/invitations/{uuid}/accept', [InvitationController::class, 'accept'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')->name('invitations.accept');
    Route::delete('/invitations/{uuid}', [InvitationController::class, 'decline'])
        ->where('uuid', '[0-9a-fA-F\-]{36}')->name('invitations.decline');
});
