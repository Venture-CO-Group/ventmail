<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\BillingController;
use Illuminate\Support\Facades\Route;

/*
 | A modul-provider a kötelező stackkel regisztrálja (web + auth + workspace); a
 | szerep-ellenőrzés a kontrollerben van (`billing.manage` — az `AbilityMap` szerint
 | KIZÁRÓLAG az `owner`).
 */
Route::get('/billing', BillingController::class)->name('billing.index');
