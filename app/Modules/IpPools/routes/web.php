<?php

declare(strict_types=1);

use App\Modules\IpPools\Http\Controllers\IpPoolController;
use Illuminate\Support\Facades\Route;

/*
 | A modul-provider a kötelező stackkel regisztrálja (web + auth + workspace); a
 | szerep-ellenőrzés a kontrollerben van (`ipPool.manage`).
 |
 | Csak OLVASÁS: a karantén és a feloldás platform-admin művelet (C2 · 87. kritérium),
 | mert egy IP kiesése minden tenant forgalmát átrendezi.
 */
Route::get('/ip-pools', IpPoolController::class)->name('ip-pools.index');
