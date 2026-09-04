<?php

declare(strict_types=1);

use App\Modules\Inbound\Http\Controllers\InboundMessageController;
use App\Modules\Inbound\Http\Controllers\InboundRouteController;
use Illuminate\Support\Facades\Route;

/*
 | A4 — bejövő route-szabályok és a tárolt levelek.
 |
 | A szabály-írás `routes:write`, az olvasás `logs:read`: a route-konfiguráció küldési
 | viselkedést változtat (továbbítás idegen címre), a levelek olvasása „csak" adathozzáférés.
 */

Route::get('/routes', [InboundRouteController::class, 'index'])
    ->middleware('api.scope:logs:read')
    ->name('v1.routes.index');

Route::post('/routes', [InboundRouteController::class, 'store'])
    ->middleware('api.scope:routes:write')
    ->name('v1.routes.store');

Route::patch('/routes/{uuid}', [InboundRouteController::class, 'update'])
    ->middleware('api.scope:routes:write')
    ->name('v1.routes.update');

Route::post('/routes/{uuid}/rotate-secret', [InboundRouteController::class, 'rotate'])
    ->middleware('api.scope:routes:write')
    ->name('v1.routes.rotate');

Route::delete('/routes/{uuid}', [InboundRouteController::class, 'destroy'])
    ->middleware('api.scope:routes:write')
    ->name('v1.routes.destroy');

Route::get('/inbound/messages', [InboundMessageController::class, 'index'])
    ->middleware('api.scope:logs:read')
    ->name('v1.inbound.messages.index');

Route::get('/inbound/messages/{uuid}', [InboundMessageController::class, 'show'])
    ->middleware('api.scope:logs:read')
    ->name('v1.inbound.messages.show');

Route::get('/inbound/messages/{uuid}/raw', [InboundMessageController::class, 'raw'])
    ->middleware('api.scope:logs:read')
    ->name('v1.inbound.messages.raw');

Route::get('/inbound/messages/{uuid}/attachments/{attachmentUuid}', [InboundMessageController::class, 'attachment'])
    ->middleware('api.scope:logs:read')
    ->name('v1.inbound.messages.attachment');
