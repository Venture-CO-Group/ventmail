<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Http\Controllers;

use App\Modules\Deliverability\Models\BlocklistCheck;
use App\Modules\Deliverability\Models\DmarcReport;
use App\Modules\Deliverability\Models\SeedTest;
use App\Modules\Domains\Models\Domain;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * KÉZBESÍTHETŐSÉG képernyője (43–48. kritérium).
 *
 * ## Ez az a képernyő, amit az ügyfél ELŐBB lát, mint mi
 *
 * A blocklistára kerülést a küldő veszi észre először — nála hirtelen senki nem kapja meg a
 * levelet. Ezért van itt együtt a három jel: a saját (vagy megosztott) IP-ink listás
 * állapota, a DMARC-jelentések aránya, és a seed-mérés inbox-aránya.
 *
 * ## A BLOCKLIST-SOR NEM tenant-adat, és ez szándékos
 *
 * A küldő IP a MI infrastruktúránk, a találat pedig MINDEN tenantot érint, aki arról az
 * IP-ről küld. Tenant-adatnak könyvelve az egyik ügyfél nem látná, hogy a másik forgalma
 * miatt került listára a közös IP — pedig épp őt sújtja. A képernyő ezért a WORKSPACE
 * POOLJAIHOZ tartozó IP-k találatait mutatja, nem az egész platformét.
 */
final class DeliverabilityController
{
    public function __invoke(): Response
    {
        Gate::authorize('analytics.view');

        // A látható IP-k: a saját és a megosztott poolok címei (a scope pontosan ezt adja).
        $poolIds = IpPool::query()->pluck('id');
        $addresses = IpAddress::query()->whereIn('ip_pool_id', $poolIds)->get();

        $checks = BlocklistCheck::query()
            ->whereIn('ip_address_id', $addresses->pluck('id'))
            ->orderByDesc('checked_at')
            ->limit(100)
            ->get();

        $listed = $checks->where('listed', true);

        return Inertia::render('Deliverability/Index', [
            'blocklist' => [
                'listedCount' => $listed->count(),
                'items' => $checks->take(25)->map(function (BlocklistCheck $check) use ($addresses) {
                    $ip = $addresses->firstWhere('id', $check->ip_address_id);

                    return [
                        'address' => (string) ($ip?->address ?? $check->target),
                        'zone' => (string) $check->zone,
                        'listed' => (bool) $check->listed,
                        'detail' => $check->detail,
                        'checkedAt' => $check->checked_at?->toIso8601String(),
                    ];
                })->values()->all(),
            ],

            'dmarc' => DmarcReport::query()
                ->with('domain')
                ->orderByDesc('range_end')
                ->limit(20)
                ->get()
                ->map(fn (DmarcReport $report) => [
                    'domain' => (string) ($report->domain?->name ?? '—'),
                    'org' => (string) $report->org_name,
                    'policy' => (string) $report->policy,
                    'total' => (int) $report->total,
                    'passed' => (int) $report->passed,
                    'failed' => (int) $report->failed,
                    // A BUKÁSI ARÁNY az, ami miatt a jelentést nézzük: egy `p=none`
                    // házirend mellett a bukás még nem jár következménnyel, de már
                    // megmutatja, mi történne `p=reject`-nél.
                    'failRate' => $report->total > 0 ? round($report->failed / $report->total * 100, 2) : 0.0,
                    'rangeEnd' => $report->range_end?->toIso8601String(),
                ])->values()->all(),

            'seedTests' => SeedTest::query()
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (SeedTest $test) => [
                    'name' => (string) $test->name,
                    'status' => (string) $test->status,
                    'inboxRate' => $test->inboxRate(),
                    'spamRate' => $test->spamRate(),
                    'missing' => (int) $test->missing,
                    'finishedAt' => $test->finished_at?->toIso8601String(),
                ])->values()->all(),

            // A DOMAIN-HITELESÍTÉS a kézbesíthetőség alapja: hitelesítetlen domainről a
            // PreSendGate 1. kapuja nem enged küldeni, tehát ide is tartozik.
            'domains' => Domain::query()->orderBy('name')->get()->map(fn (Domain $domain) => [
                'name' => (string) $domain->name,
                'status' => (string) $domain->status,
                'dmarc' => (string) $domain->dmarc_status,
            ])->values()->all(),
        ]);
    }
}
