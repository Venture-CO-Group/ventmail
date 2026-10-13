<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Modules\Domains\Models\Domain;
use App\Modules\Logs\Models\DomainDailyStat;
use App\Support\QueryBudget;
use Carbon\CarbonInterface;

/**
 * A Platform-statisztika olvasása (D175): küldött, kézbesített, bounce, panasz, nyitás,
 * kattintás domainenként és időben.
 *
 * KORLÁTOS: a `domain_daily_stats` napi sor domainenként (egy év egy domainre 365 sor), az
 * időablak legfeljebb 365 nap, és minden lekérdezés a `QueryBudget` időkorlátja alatt fut.
 * A workspace-szűrőt a modell global scope-ja adja.
 *
 * Nyitás/kattintás ESEMÉNY-szám (nem egyedi címzett) — ugyanaz a szemantika, mint a tag-
 * statisztikában; az egyedi számok a kampány-riport ténytáblájából jönnek.
 */
final class DomainStatistics
{
    public const METRICS = ['sent', 'delivered', 'bounced', 'complained', 'opened', 'clicked'];

    public function __construct(private readonly QueryBudget $budget) {}

    /**
     * @return array{totals: list<array<string, mixed>>, series: list<array<string, mixed>>, sum: array<string, int>}
     */
    public function report(CarbonInterface $from, CarbonInterface $to, ?int $domainId = null): array
    {
        return $this->budget->run(function () use ($from, $to, $domainId): array {
            $sums = implode(', ', array_map(static fn (string $metric) => "coalesce(sum({$metric}), 0) as {$metric}", self::METRICS));

            $base = fn () => DomainDailyStat::query()
                ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
                ->when($domainId !== null, fn ($query) => $query->where('domain_id', $domainId));

            $byDomain = $base()->toBase()->selectRaw('domain_id, '.$sums)->groupBy('domain_id')->get();
            $names = Domain::query()->withTrashed()->whereIn('id', $byDomain->pluck('domain_id'))->pluck('name', 'id');

            $totals = $byDomain->map(fn ($row) => [
                'domainId' => (int) $row->domain_id,
                'domain' => $row->domain_id ? (string) ($names[$row->domain_id] ?? '—') : null,
                ...$this->metrics($row),
            ])->sortByDesc('sent')->values()->all();

            // `toBase()`: az aggregátum-sor nem modell — a `day` dátum-castja különben
            // „2026-09-14 00:00:00"-t adna a nap-címkének.
            $series = $base()
                ->toBase()
                ->selectRaw("to_char(day, 'YYYY-MM-DD') as day, ".$sums)
                ->groupBy('day')
                ->orderBy('day')
                ->get()
                ->map(fn ($row) => ['day' => (string) $row->day, ...$this->metrics($row)])
                ->all();

            $sum = array_fill_keys(self::METRICS, 0);

            foreach ($totals as $row) {
                foreach (self::METRICS as $metric) {
                    $sum[$metric] += $row[$metric];
                }
            }

            return ['totals' => $totals, 'series' => $series, 'sum' => $sum];
        });
    }

    /** @return array<string, int|float|null> */
    private function metrics(object $row): array
    {
        $out = [];

        foreach (self::METRICS as $metric) {
            $out[$metric] = (int) $row->{$metric};
        }

        $sent = max(0, $out['sent']);
        $delivered = max(0, $out['delivered']);

        // Arányok: a bounce és a panasz a KÜLDÖTTHÖZ, a nyitás/kattintás a KÉZBESÍTETTHEZ.
        $out['bounceRate'] = $sent > 0 ? round($out['bounced'] / $sent * 100, 2) : null;
        $out['complaintRate'] = $sent > 0 ? round($out['complained'] / $sent * 100, 3) : null;
        $out['openRate'] = $delivered > 0 ? round($out['opened'] / $delivered * 100, 1) : null;
        $out['clickRate'] = $delivered > 0 ? round($out['clicked'] / $delivered * 100, 1) : null;

        return $out;
    }
}
