<?php

declare(strict_types=1);

use App\Modules\Events\Http\Controllers\EventsController;
use Illuminate\Support\Facades\Route;

/*
 | `GET /v1/events` — a Mailgun events API paritása (27. kritérium).
 |
 | KURZOROS lapozással: az eseménytábla folyamatosan nő, tehát offset-lapozásnál a második
 | oldal lekérésekor a sorok elcsúsznának — a hívó ugyanazt kétszer kapná, vagy sosem látná.
 */

Route::get('/events', EventsController::class)
    ->middleware('api.scope:events:read')
    ->name('v1.events.index');
