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

/*
 | A BEJÖVŐ ROUTING CSOMAG-FUNKCIÓ (C1 · 76. kritérium) — az ÍRÁSI műveleteken.
 |
 | A review megmérte, hogy az öt nevesített funkcióból KETTŐT semmi nem védett: a
 | `custom_domain`-t és a `inbound_routes`-ot sehol nem kérdezte meg senki. Vagyis egy
 | csomag, ami nem tartalmazza a bejövő routingot, ingyen megkapta.
 |
 | Az OLVASÁS szándékosan nyitva marad: a tenantnak látnia kell, MI van beállítva nála —
 | akkor is, ha a csomagja már nem tartalmazza a funkciót (lefokozás után). Az olvasás
 | elzárása azt jelentené, hogy a saját konfigurációját sem tudja megnézni ahhoz, hogy
 | eldöntse, mit tegyen vele.
 */
Route::get('/routes', [InboundRouteController::class, 'index'])
    ->middleware('api.scope:logs:read')
    ->name('v1.routes.index');

Route::post('/routes', [InboundRouteController::class, 'store'])
    ->middleware(['api.scope:routes:write', 'entitlement:inbound_routes'])
    ->name('v1.routes.store');

Route::patch('/routes/{uuid}', [InboundRouteController::class, 'update'])
    ->middleware(['api.scope:routes:write', 'entitlement:inbound_routes'])
    ->name('v1.routes.update');

Route::post('/routes/{uuid}/rotate-secret', [InboundRouteController::class, 'rotate'])
    ->middleware(['api.scope:routes:write', 'entitlement:inbound_routes'])
    ->name('v1.routes.rotate');

Route::delete('/routes/{uuid}', [InboundRouteController::class, 'destroy'])
    ->middleware(['api.scope:routes:write', 'entitlement:inbound_routes'])
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
