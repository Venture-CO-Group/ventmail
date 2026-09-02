<?php

declare(strict_types=1);

use App\Modules\Messages\Http\Controllers\SendMessageController;
use App\Modules\Messages\Http\Controllers\SendMimeController;
use Illuminate\Support\Facades\Route;

/*
 | A publikus `/v1` küldő felület (spec 8.).
 |
 | A modul-provider adja a kötelező stacket (api + throttle + api-kulcs hitelesítés);
 | itt csak a scope- és idempotencia-követelmény ül a route-okon.
 */

Route::post('/messages', SendMessageController::class)
    ->middleware(['api.scope:messages:send', 'api.idempotency'])
    ->name('v1.messages.send');

// Nyers MIME: aki saját levelet állít össze, ne kényszerüljön átírni az integrációját.
Route::post('/messages.mime', SendMimeController::class)
    ->middleware(['api.scope:messages:send', 'api.idempotency'])
    ->name('v1.messages.mime');
