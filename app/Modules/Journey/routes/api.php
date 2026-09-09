<?php

declare(strict_types=1);

use App\Modules\Journey\Http\Controllers\JourneyEventController;
use Illuminate\Support\Facades\Route;

/*
 | `POST /v1/journeys/events` — az API-ESEMÉNY trigger (69. kritérium).
 |
 | Az `api.idempotency` KÖTELEZŐ (golden rule 5): egy újrapróbált integráció-hívás
 | különben MÁSODIK journey-futást indítana ugyanarra az emberre, tehát a címzett két
 | párhuzamos levélsorozatot kapna. A journey-beléptetést a `journey_runs` részleges
 | egyedi indexe is védi, de az csak az AKTÍV futásra — egy már befejezett journey-be az
 | ismételt hívás újra beléptetne.
 */

Route::post('/journeys/events', JourneyEventController::class)
    ->middleware(['api.scope:journeys:write', 'api.idempotency'])
    ->name('v1.journeys.events.store');
