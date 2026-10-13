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
