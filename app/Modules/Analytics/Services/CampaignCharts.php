<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Models\CampaignEngagement;
use App\Modules\Analytics\Models\EngagementClientDaily;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * KAMPÁNY-GRAFIKONOK adatai (v1.1 · 8. modul) — a képernyő ÉS a PDF-riport ugyanezt hívja.
 *
 * - Nyitás/kattintás időben (D228): a ténytábla `first_opened_at` / `first_clicked_at`
 *   oszlopából, tehát EGYEDI nyitó/kattintó időpontonként (nem eseményszám). A felbontás 72 óra
 *   alatt ÓRA, fölötte NAP; a vödör a felhasználó időzónájában képződik (D11).
 * - Eszköz/kliens (D229): az `engagement_client_daily` ESEMÉNYszámai; a v1.2 előtti kampányokra
 *   nincs adat (`hasData = false`).
 * - Top linkek: a `ClickMap` rangsora, egyedi kattintó szerinti részesedéssel, és a
 *   hőtérkép-overlay kulcsával (`key` = ugyanaz a kulcsolt hash, amit a térkép tárol).
 *
 * Nyers `message_events`-scan nincs; minden a hatókör-indexen fut.
 */
final class CampaignCharts
{
    /** Ennyi óra alatt órás a felbontás. */
    public const HOURLY_LIMIT_HOURS = 72;

    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly ClickMap $clickMap,
    ) {}

    /**
     * @return array{bucket: string, points: list<array{at: string, opens: int, clicks: int}>}
     */
    public function timeline(Campaign $campaign, string $timezone): array
    {
        $this->assertContext();

        $scope = CampaignAnalytics::tag($campaign);

        $bounds = CampaignEngagement::query()
            ->where('scope', $scope)
            ->selectRaw('least(min(first_opened_at), min(first_clicked_at)) as lo, greatest(max(first_opened_at), max(first_clicked_at)) as hi')
            ->toBase()
            ->first();

        if ($bounds === null || $bounds->lo === null) {
            return ['bucket' => 'day', 'points' => []];
        }

        $lo = Carbon::parse((string) $bounds->lo);
        $hi = Carbon::parse((string) $bounds->hi);
        $start = $campaign->started_at !== null && $campaign->started_at->lt($lo) ? Carbon::parse($campaign->started_at) : $lo;
        $bucket = $start->diffInHours($hi) <= self::HOURLY_LIMIT_HOURS ? 'hour' : 'day';

        $opens = $this->bucketed($scope, 'first_opened_at', $bucket, $timezone);
        $clicks = $this->bucketed($scope, 'first_clicked_at', $bucket, $timezone);

        return ['bucket' => $bucket, 'points' => $this->fill($start, $hi, $bucket, $timezone, $opens, $clicks)];
    }

    /**
     * @return array{hasData: bool, kind: string, devices: list<array{key: string, total: int}>, clients: list<array{key: string, total: int}>}
     */
    public function devices(Campaign $campaign, string $kind = 'open'): array
    {
        $this->assertContext();

        $base = fn () => EngagementClientDaily::query()
            ->where('scope', CampaignAnalytics::tag($campaign))
            ->where('kind', $kind);

        $devices = $base()->selectRaw('device as key, sum(total) as total')->groupBy('device')->orderByDesc('total')->toBase()->get();
        $clients = $base()->selectRaw('client as key, sum(total) as total')->groupBy('client')->orderByDesc('total')->toBase()->get();

        $map = static fn ($rows): array => $rows->map(static fn ($row): array => ['key' => (string) $row->key, 'total' => (int) $row->total])->values()->all();

        return [
            'hasData' => $devices->isNotEmpty(),
            'kind' => $kind,
            'devices' => $map($devices),
            'clients' => $map($clients),
        ];
    }

    /**
     * @return list<array{url: string, key: string, clicks: int, unique: int, share: float}>
     */
    public function topLinks(Campaign $campaign, ?int $limit = null): array
    {
        $rows = $this->clickMap->forCampaign($campaign);
        $totalUnique = array_sum(array_map(static fn (array $row): int => (int) $row['unique_clicks'], $rows));

        $out = array_map(static fn (array $row): array => [
            'url' => (string) $row['url'],
            // A normalizálás idempotens: a tárolt (már normalizált) URL ugyanazt a kulcsot adja.
            'key' => ClickMap::linkKey((string) $row['url']),
            'clicks' => (int) $row['clicks'],
            'unique' => (int) $row['unique_clicks'],
            'share' => Rates::percent((int) $row['unique_clicks'], $totalUnique),
        ], $rows);

        return $limit === null ? $out : array_slice($out, 0, $limit);
    }

    /** @return array<string, int> vödör-kulcs → szám */
    private function bucketed(string $scope, string $column, string $bucket, string $timezone): array
    {
        $format = $bucket === 'hour' ? 'YYYY-MM-DD"T"HH24:00' : 'YYYY-MM-DD';

        return CampaignEngagement::query()
            ->where('scope', $scope)
            ->whereNotNull($column)
            ->selectRaw("to_char(date_trunc('{$bucket}', {$column} at time zone ?), '{$format}') as b, count(*) as n", [$timezone])
            ->groupByRaw('1')
            ->toBase()
            ->pluck('n', 'b')
            ->map(static fn ($n): int => (int) $n)
            ->all();
    }

    /**
     * @param  array<string, int>  $opens
     * @param  array<string, int>  $clicks
     * @return list<array{at: string, opens: int, clicks: int}>
     */
    private function fill(CarbonInterface $from, CarbonInterface $to, string $bucket, string $timezone, array $opens, array $clicks): array
    {
        $cursor = Carbon::parse($from)->setTimezone($timezone);
        $cursor = $bucket === 'hour' ? $cursor->startOfHour() : $cursor->startOfDay();
        $end = Carbon::parse($to)->setTimezone($timezone);
        $points = [];

        // Felső korlát: 72 óra órásan, legfeljebb 400 nap naponta — a válasz mérete korlátos.
        for ($i = 0; $cursor->lte($end) && $i < 400; $i++) {
            $key = $bucket === 'hour' ? $cursor->format('Y-m-d\TH:00') : $cursor->toDateString();
            $points[] = ['at' => $key, 'opens' => $opens[$key] ?? 0, 'clicks' => $clicks[$key] ?? 0];
            $bucket === 'hour' ? $cursor->addHour() : $cursor->addDay();
        }

        return $points;
    }

    private function assertContext(): void
    {
        if (! $this->context->hasWorkspace()) {
            throw new RuntimeException('A kampány-grafikon workspace-kontextus nélkül nem kérdezhető le.');
        }
    }
}
