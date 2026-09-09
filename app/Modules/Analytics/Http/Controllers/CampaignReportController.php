<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Services\AnalyticsExport;
use App\Modules\Analytics\Services\CampaignAnalytics;
use App\Modules\Analytics\Services\ClickMap;
use App\Modules\Campaign\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /v1/analytics/campaigns/{uuid}` — a kampány-riport API-n (74. kritérium).
 *
 * ## A CSV UGYANAZT adja, mint a JSON
 *
 * Nem külön lekérdezés: mindkettő az `AnalyticsExport`/`CampaignAnalytics` ugyanazon
 * metódusait hívja. Egy külön SQL a CSV-hez azt jelentené, hogy a letöltött fájl és az
 * API MÁST mond ugyanarról a kampányról — és a fájlt a tenant küldi tovább a vezetőségnek.
 *
 * ## A TENANT-HATÁR a modellen van
 *
 * A `Campaign` tenant-kötött, tehát az uuid-alapú keresés a global scope-on megy át: egy
 * másik workspace kampányának uuid-ja 404-et ad, nem adatot. Ezért nincs itt kézi
 * `workspace_id` szűrő, és a scope kikapcsolása sem jöhet szóba.
 *
 * ## Amit a válasz NEM tartalmaz: címzettet
 *
 * A riport aggregált. Ha címzett-szintű adatot adna, egy `analytics:read` scope-pal
 * rendelkező kulcs a teljes címlistát letölthetné — az érintetti export (`ContactGdpr`)
 * ezért külön, auditált út, ami egy emberre szól.
 */
final class CampaignReportController
{
    public function __construct(
        private readonly CampaignAnalytics $analytics,
        private readonly ClickMap $clicks,
        private readonly AnalyticsExport $export,
    ) {}

    public function __invoke(Request $request, string $uuid): JsonResponse|Response
    {
        $campaign = Campaign::query()->where('uuid', $uuid)->first();

        if ($campaign === null) {
            // 404, nem 403: egy „létezik, de nem a tiéd" válasz megmondaná, hogy az uuid
            // valós — a riport-végpont nem lehet létezés-orákulum más tenantok kampányaira.
            throw new NotFoundHttpException;
        }

        if (strtolower((string) $request->query('format', 'json')) === 'csv') {
            return new Response($this->export->campaignCsv($campaign), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                // A FÁJLNÉV a kampány uuid-ja, nem a NEVE: a név a tenant szövege, és egy
                // `Content-Disposition` fejlécbe kerülő idézőjel vagy újsor fejléc-injekció.
                'Content-Disposition' => 'attachment; filename="kampany-'.$campaign->uuid.'.csv"',
            ]);
        }

        return new JsonResponse([
            'summary' => $this->analytics->summary($campaign),
            'variants' => $this->analytics->byVariant($campaign),
            'daily' => $this->analytics->daily($campaign),
            'click_map' => $this->clicks->forCampaign($campaign),
        ]);
    }
}
