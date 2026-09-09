<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Services\CampaignAnalytics;
use App\Modules\Analytics\Services\ClickMap;
use App\Modules\Campaign\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MARKETING ANALITIKA (72–74. kritérium).
 *
 * ## UGYANAZOK A SZÁMOK, mint az API-n és a naplóban
 *
 * A `CampaignAnalytics` a `message_event_daily` folytonos aggregátumból dolgozik —
 * ugyanabból, amit a tag-statisztika és a `/v1/analytics` használ. Egy külön
 * „riport-lekérdezés" előbb-utóbb más számot adna, és akkor egyik sem hihető.
 *
 * ## A NYITÁSI ARÁNY TORZÍT, és ezt kimondjuk
 *
 * Az Apple Mail Privacy Protection előre letölti a képeket: a nyitás egy része GÉPI. A
 * képernyő ezért a kattintást emeli ki — az szándékos cselekvés —, és a nyitás mellé
 * odaírja a fenntartást.
 */
final class AnalyticsUiController
{
    public function index(): Response
    {
        Gate::authorize('analytics.view');

        return Inertia::render('Analytics/Index', [
            'campaigns' => Campaign::query()
                ->whereIn('status', ['sending', 'sent', 'paused'])
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (Campaign $campaign) => [
                    'uuid' => (string) $campaign->uuid,
                    'name' => (string) $campaign->name,
                    'status' => (string) $campaign->status,
                ])->values()->all(),
        ]);
    }

    public function show(
        Request $request,
        string $uuid,
        CampaignAnalytics $analytics,
        ClickMap $clickMap,
    ): Response {
        Gate::authorize('analytics.view');

        $campaign = Campaign::query()->with('variants')->where('uuid', $uuid)->firstOrFail();

        return Inertia::render('Analytics/Campaign', [
            'campaign' => [
                'uuid' => (string) $campaign->uuid,
                'name' => (string) $campaign->name,
                'status' => (string) $campaign->status,
            ],
            'summary' => $analytics->summary($campaign),
            // AZ A/B EREDMÉNY variánsonként: az összesített szám elrejtené, hogy melyik
            // tárgysor működött.
            'variants' => $analytics->byVariant($campaign),
            'daily' => $analytics->daily($campaign),
            'winner' => $analytics->winner($campaign)?->label,
            // A KLIKK-TÉRKÉP a retenció után is megmarad AGGREGÁLVA: a címzett-dimenzió
            // tűnik el belőle, a link-szintű szám nem (golden rule 10).
            'links' => $clickMap->forCampaign($campaign),
        ]);
    }
}
