<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Data\AnalyticsRange;
use App\Modules\Audience\Services\AudienceInsights;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Journey\Models\Journey;
use App\Modules\Logs\Services\DomainStatistics;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\QueryBudget;
use App\Support\QueryTooExpensive;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Az ANALITIKA SZEKCIÓI (v1.1 · 8. modul) — EGY helyen, a képernyő (Inertia deferred prop) ÉS a
 * PDF-riport (`RenderAnalyticsReport`) ugyanezt hívja: a letöltött riport és a képernyő nem
 * mondhat mást ugyanarról az időszakról.
 *
 * Minden szekció:
 * - fail-closed workspace-kontextussal fut (global scope-os modellek + `assertContext`);
 * - `QueryBudget` alatt (alapból 2 s): túllépésnél `available: false`, a felület „a számok most
 *   nem érhetők el" feliratot mutat, nem nullákat és nem 500-at;
 * - 300 s-ig gyorsítótárazott workspace + szekció + paraméter szerint (a riport-job a
 *   friss számot kéri: `fresh = true`).
 */
final class AnalyticsSections
{
    public const CACHE_SECONDS = 300;

    public const BUDGET_MS = 2000;

    /** A panasz-arány küszöbe (%) — a Gmail/Yahoo bulk-sender követelménye. */
    public const COMPLAINT_THRESHOLD = 0.3;

    /** A bounce-arány figyelmeztetési küszöbe (%) — a bulk-sender gyakorlat 2%-os felső határa. */
    public const BOUNCE_THRESHOLD = 2.0;

    private int $budgetMs = self::BUDGET_MS;

    private bool $fresh = false;

    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly QueryBudget $budget,
        private readonly CampaignAnalytics $campaigns,
        private readonly CampaignCharts $charts,
        private readonly AudienceInsights $audience,
        private readonly JourneyConversion $journeys,
        private readonly DomainStatistics $domains,
    ) {}

    /** A riport-job saját kerete (szekciónként 5 s) és a gyorsítótár kikerülése. */
    public function forReport(int $budgetMs = 5000): self
    {
        $clone = clone $this;
        $clone->budgetMs = $budgetMs;
        $clone->fresh = true;

        return $clone;
    }

    /** @return array<string, mixed> */
    public function campaign(Campaign $campaign, string $timezone, ?int $linkLimit = null): array
    {
        return $this->section('campaign', [$campaign->uuid, $timezone, $linkLimit], fn (): array => [
            'campaign' => [
                'uuid' => (string) $campaign->uuid,
                'name' => (string) $campaign->name,
                'status' => (string) $campaign->status,
                'startedAt' => $campaign->started_at?->toIso8601String(),
            ],
            'summary' => $this->campaigns->summary($campaign),
            'variants' => $this->campaigns->byVariant($campaign),
            'winner' => $this->campaigns->winner($campaign)?->label,
            'timeline' => $this->charts->timeline($campaign, $timezone),
            'devices' => $this->charts->devices($campaign),
            'links' => $this->charts->topLinks($campaign, $linkLimit),
        ]);
    }

    /** @return array<string, mixed> */
    public function audience(AnalyticsRange $range): array
    {
        return $this->section('audience', $range->toArray(), function () use ($range): array {
            $growth = $this->audience->growthBetween($range->from, $range->to, $range->timezone);
            // D262 (review RS1): a lemorzsolódás nevezője az időszak ELEJÉN feliratkozottak száma —
            // közvetlenül lekérdezve, nem a mai létszámból visszaszámolva (múltbeli ablakra is helyes).
            $churn = $this->audience->churnBetween($range->from, $range->to, $range->timezone);

            return [
                'growth' => $growth,
                'totals' => $this->audience->totals(),
                'churn' => ['unsubscribed' => $churn['unsubscribed'], 'new' => $churn['newSubscribed'], 'subscribedAtStart' => $churn['subscribedAtStart'], 'rate' => $churn['rate']],
                'engagement' => $this->audience->engagement(),
                'activeWindowDays' => AudienceInsights::ACTIVE_WINDOW_DAYS,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function journey(Journey $journey): array
    {
        return $this->section('journey', [$journey->uuid], function () use ($journey): array {
            $conversion = $this->journeys->forJourney($journey);

            return [
                'journey' => ['uuid' => (string) $journey->uuid, 'name' => (string) $journey->name, 'status' => (string) $journey->status],
                'nodesAvailable' => $conversion['available'],
                'rows' => $conversion['rows'],
                'goal' => $conversion['goal'],
            ];
        });
    }

    /** @return array<string, mixed> */
    public function platform(AnalyticsRange $range): array
    {
        return $this->section('platform', $range->toArray(), function () use ($range): array {
            $report = $this->domains->report($range->from, $range->to);
            $series = array_map(static fn (array $row): array => [
                'day' => (string) $row['day'],
                'sent' => (int) $row['sent'],
                'deliveredRate' => Rates::percent((int) $row['delivered'], (int) $row['sent']),
                'bounceRate' => Rates::percent((int) $row['bounced'], (int) $row['sent']),
                'complaintRate' => $row['sent'] > 0 ? round($row['complained'] / $row['sent'] * 100, 3) : 0.0,
            ], $report['series']);

            return [
                'series' => $series,
                'sum' => $report['sum'],
                'bounces' => $this->domains->bounceTypes($range->from, $range->to),
                'complaintThreshold' => self::COMPLAINT_THRESHOLD,
            ];
        });
    }

    /**
     * AZ ÁTTEKINTÉS KPI-SORA (v1.1 · D284): küldött, kézbesítési arány, egyedi megnyitás, aktív
     * kontaktok — napi sorozattal (sparkline) és az ELŐZŐ, azonos hosszú időszakkal. Ugyanazokból
     * az aggregátumokból, mint a platform- és a közönség-szekció (`domain_daily_stats`,
     * `AudienceInsights`), tehát az áttekintés és az analitika nem mondhat mást.
     *
     * @return array<string, mixed>
     */
    public function overview(AnalyticsRange $range): array
    {
        return $this->section('overview', $range->toArray(), function () use ($range): array {
            $days = $range->days();
            $current = $this->domains->report($range->from, $range->to);
            $previous = $this->domains->report($range->from->copy()->subDays($days), $range->from->copy()->subDay())['sum'];
            $sum = $current['sum'];

            $byDay = [];

            foreach ($current['series'] as $row) {
                $byDay[(string) $row['day']] = $row;
            }

            $growth = $this->audience->growthBetween($range->from, $range->to, $range->timezone);
            $totals = $this->audience->totals();
            $churn = $this->audience->churnBetween($range->from, $range->to, $range->timezone);

            $sent = $delivered = $opened = [];

            foreach ($growth as $index => $row) {
                $day = $byDay[$row['date']] ?? null;
                $sent[$index] = (int) ($day['sent'] ?? 0);
                $delivered[$index] = $day === null || (int) $day['sent'] === 0 ? null : Rates::percent((int) $day['delivered'], (int) $day['sent']);
                $opened[$index] = $day === null || (int) $day['delivered'] === 0 ? null : Rates::percent((int) $day['opened'], (int) $day['delivered']);
            }

            // AZ AKTÍV KONTAKTOK napi állapota visszafelé a mai feliratkozott létszámból (a napi nettó
            // változással) — a sparkline alakja, nem könyvelési pontosságú múltbeli létszám.
            $active = [];
            $running = (int) $totals['subscribed'];

            for ($i = count($growth) - 1; $i >= 0; $i--) {
                $active[$i] = max(0, $running);
                $running -= (int) $growth[$i]['net'];
            }

            ksort($active);

            $rate = static fn (int $numerator, int $denominator): ?float => $denominator > 0 ? Rates::percent($numerator, $denominator) : null;

            return [
                'days' => $days,
                'sent' => ['value' => (int) $sum['sent'], 'previous' => (int) $previous['sent'], 'series' => array_values($sent)],
                'deliveryRate' => ['value' => $rate((int) $sum['delivered'], (int) $sum['sent']), 'previous' => $rate((int) $previous['delivered'], (int) $previous['sent']), 'series' => array_values($delivered)],
                'openRate' => ['value' => $rate((int) $sum['opened'], (int) $sum['delivered']), 'previous' => $rate((int) $previous['opened'], (int) $previous['delivered']), 'series' => array_values($opened)],
                'activeContacts' => ['value' => (int) $totals['subscribed'], 'added' => (int) $churn['newSubscribed'], 'series' => array_values($active)],
                // A kézbesíthetőség-kártya arányai (a nevező a KÜLDÖTT — a platform-szekcióval egyezően).
                'bounceRate' => $rate((int) $sum['bounced'], (int) $sum['sent']),
                'complaintRate' => (int) $sum['sent'] > 0 ? round((int) $sum['complained'] / (int) $sum['sent'] * 100, 3) : null,
            ];
        });
    }

    /**
     * @param  array<int|string, mixed>  $params
     * @param  callable(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function section(string $name, array $params, callable $build): array
    {
        if (! $this->context->hasWorkspace()) {
            throw new RuntimeException('Az analitika workspace-kontextus nélkül nem kérdezhető le.');
        }

        $key = sprintf('analytics:v1:%d:%s:%s', $this->context->workspaceId(), $name, hash('sha256', (string) json_encode($params)));

        if (! $this->fresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $data = ['available' => true, ...$this->budget->run($build, $this->budgetMs)];
        } catch (QueryTooExpensive) {
            // A túllépés NEM kerül a gyorsítótárba: a következő kérés újra próbálja.
            return ['available' => false];
        }

        Cache::put($key, $data, self::CACHE_SECONDS);

        return $data;
    }
}
