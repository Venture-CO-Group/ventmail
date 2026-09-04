<?php

declare(strict_types=1);

use App\Modules\Inbound\Http\Controllers\InboundUiController;
use Illuminate\Support\Facades\Route;

/*
 | A bejövő route-ok képernyője.
 |
 | Az `auth` + `workspace` EXPLICIT. A modul `/v1` API-ja kulccsal hitelesít, ezért a provider
 | `routesPrecedeAuthentication`-je be van kapcsolva — az viszont a WEB-stackről is leveszi az
 | alapértelmezett őrt. Ez a képernyő ügyfél-levelezést mutat: hitelesítés nélkül nem
 | maradhat. A KÉPESSÉG-ellenőrzés ezen felül a kontrollerben van.
 */

Route::get('/inbound', [InboundUiController::class, 'index'])
    ->middleware(['auth', 'workspace'])
    ->name('inbound.index');
