<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Journey\Models\Journey;

/**
 * EXPORT CSV-be (74. kritérium).
 *
 * ## Amit az export SOHA nem tartalmaz: címzettet
 *
 * Az export letölthető fájl, ami elhagyja a rendszert — e-mailben, felhőmappában,
 * megosztott linken. Az AGGREGÁLT riportban nincs egyetlen cím sem, és ez nem véletlen: ha
 * lenne, egy „riport" letöltése a teljes címlista exportja lenne, jogosultsági és
 * adatvédelmi kontroll nélkül. Aki címzett-szintű adatot akar, az érintetti exportot
 * használja (`ContactGdpr::export`), ami auditált és egy emberre szól.
 *
 * ## Miért nem `fputcsv` fájlba
 *
 * Mert a hívó dönti el, hova megy: HTTP-válasz, ütemezett feladat, teszt. A sztring-alak a
 * legkisebb közös nevező, és a riport-CSV mérete (soronként egy kampány/variáns/link) nem
 * indokol streamelést.
 *
 * ## A SZÁMOK UGYANAZOK, mint a felületen
 *
 * Az export nem külön lekérdezés: ugyanazokat a szolgáltatásokat hívja, mint a riport-oldal.
 * Egy külön SQL itt azt jelentené, hogy a letöltött fájl és a képernyő MÁST mond — és a
 * tenant a fájlt küldi tovább a vezetőségnek.
 */
final class AnalyticsExport
{
    public function __construct(
        private readonly CampaignAnalytics $campaigns,
        private readonly JourneyAnalytics $journeys,
        private readonly ClickMap $clicks,
    ) {}

    /** A kampány összesítője EGY sorban, fejléccel. */
    public function campaignCsv(Campaign $campaign): string
    {
        $summary = $this->campaigns->summary($campaign);

        return $this->csv([array_keys($summary)], [array_values($summary)]);
    }

    /** A VARIÁNSOK soronként — az A/B teszt eredménye táblázatban. */
    public function variantsCsv(Campaign $campaign): string
    {
        $rows = $this->campaigns->byVariant($campaign);

        if ($rows === []) {
            return $this->csv([['label']], []);
        }

        return $this->csv([array_keys($rows[0])], array_map('array_values', $rows));
    }

    /** A KLIKK-TÉRKÉP soronként, rangsorban. */
    public function clickMapCsv(Campaign $campaign): string
    {
        $rows = $this->clicks->forCampaign($campaign);

        $header = ['url', 'clicks', 'unique_clicks', 'first_clicked_at', 'last_clicked_at'];

        return $this->csv(
            [$header],
            array_map(static fn (array $row): array => [
                $row['url'],
                $row['clicks'],
                $row['unique_clicks'],
                $row['first_clicked_at'],
                $row['last_clicked_at'],
            ], $rows)
        );
    }

    public function journeyCsv(Journey $journey): string
    {
        $summary = $this->journeys->summary($journey);

        return $this->csv([array_keys($summary)], [array_values($summary)]);
    }

    /**
     * @param  list<list<string>>  $header
     * @param  list<list<mixed>>  $rows
     */
    private function csv(array $header, array $rows): string
    {
        // `php://temp`: a `fputcsv` végzi az idézőjelezést és az escape-elést. Kézzel
        // összefűzött CSV-ben egy vessző vagy idézőjel a kampány NEVÉBEN elcsúsztatja a
        // mezőket — és a hibát az veszi észre, aki a táblázatot megnyitja.
        $handle = fopen('php://temp', 'r+');

        foreach (array_merge($header, $rows) as $row) {
            fputcsv($handle, array_map(static fn ($value) => match (true) {
                is_bool($value) => $value ? '1' : '0',
                $value === null => '',
                default => (string) $value,
            }, $row));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
