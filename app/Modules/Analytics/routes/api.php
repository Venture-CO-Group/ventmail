<?php

declare(strict_types=1);

use App\Modules\Analytics\Http\Controllers\CampaignReportController;
use Illuminate\Support\Facades\Route;

/*
 | `GET /v1/analytics/campaigns/{uuid}` — kampány-riport (74. kritérium).
 |
 | A `?format=csv` UGYANAZOKAT a számokat adja, mint a JSON: mindkettő ugyanazt a
 | szolgáltatást hívja. Egy külön SQL a CSV-hez azt jelentené, hogy a letöltött fájl és az
 | API mást mond ugyanarról a kampányról.
 |
 | A scope `analytics:read` — SAJÁT scope, nem a `logs:read` újrahasznosítása: a marketing
 | riport és a platform-napló két különböző jogosultsági kérdés (egy integráció, ami a
 | kézbesítési naplót olvassa, nem feltétlenül láthatja a kampány-teljesítményt).
 */

Route::get('/analytics/campaigns/{uuid}', CampaignReportController::class)
    ->middleware('api.scope:analytics:read')
    ->where('uuid', '[0-9a-fA-F-]{36}')
    ->name('v1.analytics.campaign');
