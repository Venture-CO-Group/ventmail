<?php

declare(strict_types=1);

use App\Modules\Webhooks\Http\Controllers\WebhookDeliveryController;
use App\Modules\Webhooks\Http\Controllers\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

/*
 | Webhook-kézbesítések listája és ÚJRAJÁTSZÁSA (28. kritérium).
 |
 | A dead-letter sor csak akkor ér valamit, ha van belőle kivezető út: az ügyfél megjavítja
 | a végpontot, és újraküldeti, ami nem jutott el.
 */

Route::get('/webhooks/deliveries', [WebhookDeliveryController::class, 'index'])
    ->middleware('api.scope:logs:read')
    ->name('v1.webhooks.deliveries.index');

Route::post('/webhooks/deliveries/{uuid}/replay', [WebhookDeliveryController::class, 'replay'])
    ->middleware('api.scope:webhooks:write')
    ->name('v1.webhooks.deliveries.replay');

/*
 | A VÉGPONTOK kezelése. Enélkül a fenti kézbesítési lánc sosem indulna el: nincs kinek
 | küldeni. A titok a létrehozás (és a rotáció) válaszában látszik EGYSZER.
 */

Route::get('/webhooks/endpoints', [WebhookEndpointController::class, 'index'])
    ->middleware('api.scope:logs:read')
    ->name('v1.webhooks.endpoints.index');

Route::post('/webhooks/endpoints', [WebhookEndpointController::class, 'store'])
    ->middleware('api.scope:webhooks:write')
    ->name('v1.webhooks.endpoints.store');

Route::patch('/webhooks/endpoints/{uuid}', [WebhookEndpointController::class, 'update'])
    ->middleware('api.scope:webhooks:write')
    ->name('v1.webhooks.endpoints.update');

Route::post('/webhooks/endpoints/{uuid}/rotate-secret', [WebhookEndpointController::class, 'rotate'])
    ->middleware('api.scope:webhooks:write')
    ->name('v1.webhooks.endpoints.rotate');

Route::delete('/webhooks/endpoints/{uuid}', [WebhookEndpointController::class, 'destroy'])
    ->middleware('api.scope:webhooks:write')
    ->name('v1.webhooks.endpoints.destroy');
