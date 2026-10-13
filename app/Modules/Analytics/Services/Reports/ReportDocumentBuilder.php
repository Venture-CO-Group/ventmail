<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services\Reports;

use App\Modules\Analytics\Data\AnalyticsRange;
use App\Modules\Analytics\Data\ReportDocument;
use App\Modules\Analytics\Models\AnalyticsReport;
use App\Modules\Analytics\Services\AnalyticsSections;
use App\Modules\Analytics\Services\BrandingService;
use App\Modules\Analytics\Services\CampaignAnalytics;
use App\Modules\Analytics\Services\EventCounters;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Journey\Models\Journey;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Support\Carbon;
use NumberFormatter;

/**
 * A RIPORT-HTML összeállítása (v1.1 · 8. modul) — UGYANAZOKBÓL a szolgáltatásokból, mint a
 * képernyő (`AnalyticsSections`), a kérő nyelvén és időzónájában (a riport-soron rögzítve).
 *
 * ## Tenant-adat a Chromiumban (R4)
 *
 * - Minden tenant-szöveg a Blade `{{ }}`-n át, ESCAPE-elve kerül a HTML-be; tenant-HTML (a
 *   sablon törzse) a riportba NEM kerül.
 * - A grafikon-adat `<script type="application/json">`-ban, `JSON_HEX_*` kódolással — `</script>`
 *   nem törhet ki belőle.
 * - Egyetlen külső erőforrás sincs: a JS, a CSS és a logó fájl-csatolmány, relatív hivatkozással;
 *   a meta-CSP csak `file:`-t enged.
 * - A brand-szín a szerveren validált `#RRGGBB` (BrandColor), CSS-változóként (`--vm-brand`).
 * - Címzett-cím a riportban nincs (csak aggregátum).
 */
final class ReportDocumentBuilder
{
    public function __construct(
        private readonly AnalyticsSections $sections,
        private readonly BrandingService $branding,
        private readonly ReportAssets $assets,
        private readonly EventCounters $counters,
    ) {}

    public function build(AnalyticsReport $report, Workspace $workspace, bool $strictAssets): ReportDocument
    {
        $t = new ReportTranslator((string) $report->locale);
        $tz = (string) $report->timezone;
        $sections = array_values(array_intersect(AnalyticsReport::SECTIONS, (array) $report->sections));
        $data = $this->sections->forReport((int) config('analytics.reports.section_budget_ms', 5000));
        $number = new NumberFormatter((string) $report->locale, NumberFormatter::DECIMAL);
        $fmt = static fn (int|float $value, int $digits = 0): string => (string) $number->format(round((float) $value, $digits));
        $pct = static fn (int|float $value, int $digits = 1): string => $fmt($value, $digits).'%';

        $view = [
            't' => $t,
            'locale' => (string) $report->locale,
            'sections' => $sections,
            'kind' => (string) $report->kind,
            'kpis' => [],
            'tables' => [],
            'notes' => [],
            'charts' => [],
        ];

        if ($report->kind === 'campaign') {
            $campaign = Campaign::query()->where('uuid', (string) $report->campaign_uuid)->firstOrFail();
            $range = $this->campaignRange($campaign, $tz);
            $c = $data->campaign($campaign, $tz, (int) config('analytics.reports.top_links', 25));
            $view['title'] = $t->get('analytics.report.coverCampaign');
            $view['subtitle'] = (string) $campaign->name;

            if (! $c['available']) {
                $view['notes'][] = $t->get('analytics.unavailable');
            } else {
                $s = $c['summary'];
                $view['kpis'] = [
                    [$t->get('analytics.kpi.recipients'), $fmt($s['recipients'])],
                    [$t->get('analytics.kpi.delivered'), $fmt($s['delivered'])],
                    [$t->get('analytics.kpi.openRate'), $pct($s['open_rate'])],
                    [$t->get('analytics.kpi.clickRate'), $pct($s['click_rate'])],
                    [$t->get('analytics.kpi.bounceRate'), $pct($s['bounce_rate'])],
                    [$t->get('analytics.kpi.unsubscribeRate'), $pct($s['unsubscribe_rate'])],
                ];

                $view['charts']['timeline'] = [
                    'title' => $t->get('analytics.chart.openClick'),
                    'note' => $t->get('analytics.uniqueNote'),
                    'type' => 'line',
                    'labels' => array_column($c['timeline']['points'], 'at'),
                    'series' => [
                        ['name' => $t->get('analytics.series.opens'), 'data' => array_column($c['timeline']['points'], 'opens'), 'token' => '--vt-color-accent-500'],
                        ['name' => $t->get('analytics.series.clicks'), 'data' => array_column($c['timeline']['points'], 'clicks'), 'token' => '--vt-color-cyan-500'],
                    ],
                ];

                if ($c['devices']['hasData']) {
                    $view['charts']['clients'] = [
                        'title' => $t->get('analytics.chart.devices'),
                        'note' => $t->get('analytics.eventsNote'),
                        'type' => 'pie',
                        'items' => array_map(static fn (array $row): array => ['name' => $t->get('analytics.client.'.$row['key']), 'value' => $row['total']], $c['devices']['clients']),
                    ];
                } else {
                    $view['notes'][] = $t->get('analytics.noDataBeforeV12');
                }

                if ($c['variants'] !== []) {
                    $view['tables'][] = [
                        'title' => $t->get('analytics.chart.variants'),
                        'head' => [$t->get('analytics.table.variant'), $t->get('analytics.kpi.delivered'), $t->get('analytics.kpi.openRate'), $t->get('analytics.kpi.clickRate')],
                        'rows' => array_map(static fn (array $v): array => [
                            $v['label'].($c['winner'] === $v['label'] ? ' · '.$t->get('analytics.winner') : ''),
                            $fmt($v['delivered']), $pct($v['open_rate']), $pct($v['click_rate']),
                        ], $c['variants']),
                    ];
                }

                if (in_array('top_links', $sections, true)) {
                    $view['tables'][] = [
                        'title' => $t->get('analytics.chart.topLinks'),
                        'head' => [$t->get('analytics.table.url'), $t->get('analytics.table.unique'), $t->get('analytics.table.clicks'), $t->get('analytics.table.share')],
                        'rows' => array_map(static fn (array $l): array => [$l['url'], $fmt($l['unique']), $fmt($l['clicks']), $pct($l['share'])], $c['links']),
                        'mono' => 0,
                    ];
                }
            }
        } else {
            $range = AnalyticsRange::resolve('custom', $report->period_from?->toDateString(), $report->period_to?->toDateString(), $tz);
            $view['title'] = $t->get('analytics.report.coverPeriod');
            $view['subtitle'] = (string) $workspace->name;

            $platform = $data->platform($range);
            $audience = $data->audience($range);

            if ($platform['available'] ?? false) {
                $sum = $platform['sum'];
                $view['kpis'] = [
                    [$t->get('analytics.kpi.sent'), $fmt($sum['sent'])],
                    [$t->get('analytics.kpi.delivered'), $fmt($sum['delivered'])],
                    [$t->get('analytics.kpi.bounceRate'), $pct($sum['sent'] > 0 ? $sum['bounced'] / $sum['sent'] * 100 : 0)],
                    [$t->get('analytics.kpi.complaintRate'), $pct($sum['sent'] > 0 ? $sum['complained'] / $sum['sent'] * 100 : 0, 2)],
                ];
            }

            if ($audience['available'] ?? false) {
                $view['kpis'][] = [$t->get('analytics.kpi.newContacts'), $fmt($audience['churn']['new'])];
                $view['kpis'][] = [$t->get('analytics.kpi.churnRate'), $pct($audience['churn']['rate'])];

                $view['charts']['growth'] = [
                    'title' => $t->get('analytics.chart.growth'),
                    'type' => 'bar',
                    'labels' => array_column($audience['growth'], 'date'),
                    'series' => [
                        ['name' => $t->get('analytics.series.new'), 'data' => array_column($audience['growth'], 'new'), 'token' => '--vt-color-accent-500', 'stack' => 'g'],
                        ['name' => $t->get('analytics.series.unsubscribed'), 'data' => array_map(static fn (int $n): int => -$n, array_column($audience['growth'], 'unsubscribed')), 'token' => '--vt-color-danger', 'stack' => 'g'],
                        ['name' => $t->get('analytics.series.net'), 'data' => array_column($audience['growth'], 'net'), 'token' => '--vt-color-neutral-700', 'line' => true],
                    ],
                ];
                $view['charts']['engagement'] = [
                    'title' => $t->get('analytics.chart.engagement'),
                    'note' => $t->get('analytics.tierWindow', ['days' => $audience['activeWindowDays']]),
                    'type' => 'pie',
                    'items' => array_map(static fn (array $row): array => ['name' => $t->get('analytics.tier.'.$row['key']), 'value' => $row['count']], $audience['engagement']),
                ];
            } else {
                $view['notes'][] = $t->get('analytics.unavailable');
            }

            $view['tables'][] = $this->campaignList($range, $t, $fmt, $pct);

            if (in_array('journeys', $sections, true)) {
                foreach (Journey::query()->orderByDesc('id')->limit((int) config('analytics.reports.max_journeys', 20))->get() as $journey) {
                    $j = $data->journey($journey);

                    if (! $j['available'] || ! $j['nodesAvailable']) {
                        $view['notes'][] = $journey->name.': '.$t->get('analytics.unavailable');

                        continue;
                    }

                    $view['tables'][] = [
                        'title' => $t->get('analytics.chart.journey').' · '.$journey->name,
                        'caption' => $t->get('analytics.goal', ['rate' => $pct($j['goal']['rate']), 'reached' => $fmt($j['goal']['reached']), 'entered' => $fmt($j['goal']['entered'])]),
                        'head' => [$t->get('analytics.table.node'), $t->get('analytics.table.entered'), $t->get('analytics.table.movedOn'), $t->get('analytics.table.conversion')],
                        'rows' => array_map(static fn (array $row): array => [
                            str_repeat('· ', min(6, (int) $row['depth'])).($row['label'] !== '' ? $row['label'] : $t->get('journeyCanvas.nodes.'.$row['type']))
                                .($row['branch'] !== null ? ' ('.$t->get('analytics.branch', ['name' => $row['branch']]).')' : ''),
                            $fmt($row['entered']), $fmt($row['movedOn']), $pct($row['conversion']),
                        ], $j['rows']),
                    ];
                }
            }
        }

        if (in_array('deliverability', $sections, true)) {
            $platform ??= $data->platform($range);

            if ($platform['available'] ?? false) {
                $view['charts']['deliverability'] = [
                    'title' => $t->get('analytics.chart.deliverability'),
                    'type' => 'line',
                    'percent' => true,
                    'threshold' => ['value' => $platform['complaintThreshold'], 'name' => $t->get('analytics.series.threshold')],
                    'labels' => array_column($platform['series'], 'day'),
                    'series' => [
                        ['name' => $t->get('analytics.series.delivered'), 'data' => array_column($platform['series'], 'deliveredRate'), 'token' => '--vt-color-success'],
                        ['name' => $t->get('analytics.series.bounced'), 'data' => array_column($platform['series'], 'bounceRate'), 'token' => '--vt-color-warning'],
                        ['name' => $t->get('analytics.series.complained'), 'data' => array_column($platform['series'], 'complaintRate'), 'token' => '--vt-color-danger', 'threshold' => true],
                    ],
                ];

                $bounces = $platform['bounces'];
                $items = [];

                foreach ($bounces['classes'] as $class => $count) {
                    $items[] = ['name' => $t->get('analytics.bounce.'.$class), 'value' => $count];
                }

                $items[] = ['name' => $t->get('analytics.bounce.unclassified'), 'value' => $bounces['unclassified']];

                $view['tables'][] = [
                    'title' => $t->get('analytics.chart.bounces'),
                    'head' => [$t->get('analytics.table.type'), $t->get('analytics.table.count')],
                    'rows' => array_map(static fn (array $item): array => [$item['name'], $fmt($item['value'])], $items),
                ];

                if ($bounces['total'] > 0) {
                    $view['charts']['bounces'] = ['title' => $t->get('analytics.chart.bounces'), 'type' => 'pie', 'items' => array_values(array_filter($items, static fn (array $i): bool => $i['value'] > 0))];
                }
            } else {
                $view['notes'][] = $t->get('analytics.unavailable');
            }
        }

        if (! in_array('charts', $sections, true)) {
            $view['charts'] = [];
        }

        if (! in_array('kpis', $sections, true)) {
            $view['kpis'] = [];
        }

        // BRANDING: a saját sor vagy a Venture-alapértelmezés (a sub-account nem örököl).
        $row = $this->branding->current();
        $brand = $this->branding->resolve($row);
        $logo = $row !== null ? $this->branding->logoBytes($row) : null;
        $logoName = $logo !== null ? 'logo.'.match ($row?->logo_mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        } : 'logo.svg';

        $bundle = $this->assets->load($strictAssets);
        $generated = Carbon::now($tz)->locale((string) $report->locale);

        $view += [
            'brand' => $brand,
            'companyName' => $brand['companyName'] ?? (string) $workspace->name,
            'logoFile' => $logoName,
            'dateRange' => $range->from->copy()->locale((string) $report->locale)->isoFormat('LL').' – '.$range->to->copy()->locale((string) $report->locale)->isoFormat('LL'),
            'generatedAt' => $t->get('analytics.report.generatedAt', ['date' => $generated->isoFormat('LLL'), 'tz' => $tz]),
            'chartJson' => (string) json_encode(['charts' => $view['charts']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];

        $html = view('analytics::report.document', $view)->render();
        $footer = view('analytics::report.footer', [
            'text' => $brand['footerText'] ?? $view['companyName'],
            'css' => $bundle['css'],
            'locale' => (string) $report->locale,
        ])->render();

        return new ReportDocument($html, $footer, [
            'report.js' => $bundle['js'],
            'report.css' => $bundle['css'],
            $logoName => $logo ?? $this->assets->defaultLogo(),
        ]);
    }

    /** A kampány riport-időszaka: az indulás napjától máig, legfeljebb 366 nap. */
    private function campaignRange(Campaign $campaign, string $tz): AnalyticsRange
    {
        $today = Carbon::now($tz)->startOfDay();
        $start = Carbon::parse($campaign->started_at ?? $campaign->created_at ?? $today)->setTimezone($tz)->startOfDay();
        // A vége MA: a nyitás/kattintás a kiküldés befejezése után is érkezik.
        $end = $today;

        if ($start->gt($end)) {
            $start = $end->copy();
        }

        if ($start->diffInDays($end) + 1 > AnalyticsRange::MAX_DAYS) {
            $start = $end->copy()->subDays(AnalyticsRange::MAX_DAYS - 1);
        }

        return AnalyticsRange::resolve('custom', $start->toDateString(), $end->toDateString(), $tz);
    }

    /**
     * @param  callable(int|float, int=): string  $fmt
     * @param  callable(int|float, int=): string  $pct
     * @return array<string, mixed>
     */
    private function campaignList(AnalyticsRange $range, ReportTranslator $t, callable $fmt, callable $pct): array
    {
        $campaigns = Campaign::query()
            ->whereIn('status', ['sending', 'sent', 'paused'])
            ->whereBetween('started_at', [$range->from->copy()->utc(), $range->to->copy()->addDay()->utc()])
            ->orderByDesc('started_at')
            ->limit((int) config('analytics.reports.max_campaigns', 50))
            ->get();

        return [
            'title' => $t->get('analytics.report.campaignList'),
            'head' => [$t->get('analytics.table.campaign'), $t->get('analytics.kpi.recipients'), $t->get('analytics.kpi.delivered'), $t->get('analytics.kpi.openRate'), $t->get('analytics.kpi.clickRate')],
            'rows' => $campaigns->map(function (Campaign $campaign) use ($fmt, $pct): array {
                $counts = $this->counters->forScope(CampaignAnalytics::tag($campaign));
                $rates = $this->counters->rates($counts);

                return [(string) $campaign->name, $fmt((int) $campaign->recipient_count), $fmt($counts['delivered']), $pct($rates['open_rate']), $pct($rates['click_rate'])];
            })->all(),
            'empty' => $t->get('analytics.noData'),
        ];
    }
}
