<?php

declare(strict_types=1);

use App\Modules\Webhooks\Http\Controllers\WebhookDeliveryController;
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
