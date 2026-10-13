<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Data\AnalyticsRange;
use App\Modules\Analytics\Services\AnalyticsExport;
use App\Modules\Analytics\Services\AnalyticsSections;
use App\Modules\Analytics\Services\BrandingService;
use App\Modules\Analytics\Services\CampaignAnalytics;
use App\Modules\Analytics\Services\ClickMap;
use App\Modules\Analytics\Services\HeatmapRenderer;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Journey\Models\Journey;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\WorkspaceTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * MARKETING ANALITIKA (72–74. kritérium; v1.1 · 8. modul: grafikonok, export, riport).
 *
 * ## UGYANAZOK A SZÁMOK, mint az API-n, a naplóban és a PDF-ben
 *
 * A szekciók (`AnalyticsSections`) a képernyő és a riport közös forrása. A képernyőn
 * szekciónként HALASZTOTT (`Inertia::defer`) propként jönnek: az oldal azonnal kirajzolódik, a
 * drágább aggregátumok párhuzamosan töltődnek, és egy túl drága szekció (`available: false`) a
 * többit nem tartja vissza.
 *
 * ## A NYITÁSI ARÁNY TORZÍT, és ezt kimondjuk
 *
 * Az Apple Mail Privacy Protection előre letölti a képeket: a nyitás egy része GÉPI. A
 * képernyő ezért a kattintást emeli ki — az szándékos cselekvés —, és a nyitás mellé
 * odaírja a fenntartást.
 */
final class AnalyticsUiController
{
    public function index(Request $request, AnalyticsSections $sections, WorkspaceContext $context, BrandingService $branding): Response
    {
        Gate::authorize('analytics.view');

        $timezone = WorkspaceTimezone::for($context->workspace(), $request->user());
        $range = AnalyticsRange::resolve($request->query('range'), $request->query('from'), $request->query('to'), $timezone);

        $campaigns = Campaign::query()
            ->whereIn('status', ['sending', 'sent', 'paused'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $journeys = Journey::query()->orderByDesc('id')->limit(20)->get();

        // A KIVÁLASZTOTT kampány / journey: a kérésből (idegen uuid → 404 a global scope miatt),
        // különben a legutóbbi.
        $campaign = $request->filled('campaign')
            ? Campaign::query()->where('uuid', (string) $request->query('campaign'))->firstOrFail()
            : $campaigns->first();
        $journey = $request->filled('journey')
            ? Journey::query()->where('uuid', (string) $request->query('journey'))->firstOrFail()
            : $journeys->first();

        return Inertia::render('Analytics/Index', [
            'campaigns' => $campaigns->map(fn (Campaign $c) => [
                'uuid' => (string) $c->uuid,
                'name' => (string) $c->name,
                'status' => (string) $c->status,
            ])->values()->all(),
            'journeys' => $journeys->map(fn (Journey $j) => ['uuid' => (string) $j->uuid, 'name' => (string) $j->name])->values()->all(),
            'selection' => [
                'campaign' => $campaign?->uuid,
                'journey' => $journey?->uuid,
                ...$range->toArray(),
                'today' => now($timezone)->toDateString(),
            ],
            'canManageBranding' => Gate::allows('workspace.manage'),
            'branding' => self::brandingPreview($branding, $context),
            'campaignSection' => Inertia::defer(fn () => $campaign === null ? null : $sections->campaign($campaign, $timezone), 'campaign'),
            'audienceSection' => Inertia::defer(fn () => $sections->audience($range), 'audience'),
            'journeySection' => Inertia::defer(fn () => $journey === null ? null : $sections->journey($journey), 'journey'),
            'platformSection' => Inertia::defer(fn () => $sections->platform($range), 'platform'),
        ]);
    }

    /**
     * A riport-borító előnézetének adatai: a saját branding vagy a Venture-alapértelmezés.
     *
     * @return array{color: ?string, coverText: string, companyName: string, footerText: string, logoUrl: ?string}
     */
    public static function brandingPreview(BrandingService $branding, WorkspaceContext $context): array
    {
        $resolved = $branding->resolve($branding->current());
        $company = $resolved['companyName'] ?? (string) $context->workspace()->name;

        return [
            'color' => $resolved['color'],
            'coverText' => $resolved['coverText'],
            'companyName' => $company,
            'footerText' => $resolved['footerText'] ?? $company,
            // Hitelesített végpont (nincs publikus URL); a verzió-paraméter a gyorsítótárat töri.
            'logoUrl' => $resolved['hasLogo'] ? '/settings/branding/logo?v='.substr(hash('sha256', (string) $resolved['updatedAt']), 0, 12) : null,
        ];
    }

    public function show(
        Request $request,
        string $uuid,
        CampaignAnalytics $analytics,
        ClickMap $clickMap,
        AnalyticsSections $sections,
        WorkspaceContext $context,
    ): Response {
        Gate::authorize('analytics.view');

        $campaign = Campaign::query()->with('variants')->where('uuid', $uuid)->firstOrFail();
        $timezone = WorkspaceTimezone::for($context->workspace(), $request->user());

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
            'charts' => Inertia::defer(fn () => $sections->campaign($campaign, $timezone), 'charts'),
        ]);
    }

    /**
     * A hőtérkép-kártya exportja (D230): a top linkek CSV-je — ugyanaz a szolgáltatás, mint a
     * `/v1/analytics/campaigns/{uuid}?format=csv&section=clicks`.
     */
    public function linksCsv(string $uuid, AnalyticsExport $export): HttpResponse
    {
        Gate::authorize('analytics.view');

        $campaign = Campaign::query()->where('uuid', $uuid)->firstOrFail();

        return response($export->clickMapCsv($campaign), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="top-links-'.$campaign->uuid.'.csv"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * A KATTINTÁSI HŐTÉRKÉP sablon-HTML-je (`data-vm-link` kulcsokkal). A tenant tartalma: a válasz
     * `sandbox` CSP-vel megy, a szülő szkript nélküli, sandboxolt iframe-ben (`srcdoc`) mutatja.
     */
    public function heatmap(string $uuid, HeatmapRenderer $renderer): HttpResponse
    {
        Gate::authorize('analytics.view');

        $campaign = Campaign::query()->where('uuid', $uuid)->firstOrFail();
        $result = $renderer->render($campaign);

        return response($result['html'] ?? '', $result['html'] === null ? 204 : 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "sandbox allow-same-origin; default-src 'none'; img-src https: data:; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Template-Changed' => $result['templateChanged'] ? '1' : '0',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
