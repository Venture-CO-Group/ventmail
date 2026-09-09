<?php

declare(strict_types=1);

use App\Modules\Campaign\Http\Controllers\CampaignUiController;
use Illuminate\Support\Facades\Route;

/*
 | A modul-provider a kötelező stackkel regisztrálja (web + auth + workspace).
 |
 | A SZEREP-ellenőrzés kettős: a szerkesztés `campaign.create`, az indítás és az ütemezés
 | `campaign.publish` — az `author` szerep írhat kampányt, de nem küldheti ki.
 */
Route::get('/campaigns', [CampaignUiController::class, 'index'])->name('campaigns.index');
Route::post('/campaigns', [CampaignUiController::class, 'store'])->name('campaigns.store');
Route::get('/campaigns/{uuid}', [CampaignUiController::class, 'show'])->name('campaigns.show');
Route::put('/campaigns/{uuid}', [CampaignUiController::class, 'update'])->name('campaigns.update');
Route::post('/campaigns/{uuid}/transition', [CampaignUiController::class, 'transition'])->name('campaigns.transition');
