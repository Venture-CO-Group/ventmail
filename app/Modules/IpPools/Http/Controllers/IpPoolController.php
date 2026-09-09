<?php

declare(strict_types=1);

namespace App\Modules\IpPools\Http\Controllers;

use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * IP-POOLOK ÉS WARMUP képernyője (17., 87. kritérium).
 *
 * ## Miért OLVASHATÓ, és miért nem szerkeszthető innen
 *
 * A pool és a benne lévő IP a MI infrastruktúránk: a karanténba tétel és a feloldás
 * platform-admin művelet (C2), mert egy IP kiesése MINDEN tenant forgalmát átirányítja
 * azokra, amik maradtak. Amit a tenantnak látnia KELL: melyik poolból megy a levele, hol
 * tart a warmup, és mennyire van kihasználva a napi keret — mert ha a keret elfogy, a
 * levele nem elveszik, hanem CSÚSZIK, és ezt magától nem tudná megmagyarázni.
 */
final class IpPoolController
{
    public function __invoke(): Response
    {
        Gate::authorize('ipPool.manage');

        /*
         | A POOLOK a workspace scope-ján keresztül jönnek: a `IpPool` tenant- VAGY
         | platform-szintű (`workspace_id IS NULL` a megosztott pool), és a
         | `WorkspaceOrPlatformScope` pontosan a kettőt adja — a MIENKET és a KÖZÖSET.
         | Idegen tenant dedikált poolja nem fér bele.
         */
        $pools = IpPool::query()->orderBy('name')->get();

        $addresses = IpAddress::query()
            ->whereIn('ip_pool_id', $pools->pluck('id'))
            ->orderBy('ip_pool_id')
            ->orderBy('address')
            ->get();

        return Inertia::render('IpPools/Index', [
            'pools' => $pools->map(fn (IpPool $pool) => [
                'uuid' => (string) $pool->uuid,
                'name' => (string) $pool->name,
                'type' => (string) $pool->type,
                // A MEGOSZTOTT poolt meg kell különböztetni a sajáttól: a reputációját
                // mások forgalma is alakítja.
                'shared' => $pool->workspace_id === null,
            ])->values()->all(),

            'addresses' => $addresses->map(function (IpAddress $ip) use ($pools) {
                $pool = $pools->firstWhere('id', $ip->ip_pool_id);
                $limit = (int) $ip->daily_limit;
                $sent = (int) $ip->sent_today;

                return [
                    'address' => (string) $ip->address,
                    'hostname' => (string) $ip->hostname,
                    'pool' => (string) ($pool?->name ?? '—'),
                    'status' => (string) $ip->status,
                    'warmupState' => (string) $ip->warmup_state,
                    'dailyLimit' => $limit,
                    'sentToday' => $sent,
                    // A KIHASZNÁLTSÁG az, amit ránézésre keresünk: a warmup-görbe betartása
                    // vagy a limit súrolása. Nulla limitnél `null`, nem 0% — a kettő más.
                    'utilisation' => $limit > 0 ? round($sent / $limit * 100, 1) : null,
                    'reputation' => $ip->reputation_score === null ? null : (float) $ip->reputation_score,
                    'quarantinedAt' => $ip->quarantined_at?->toIso8601String(),
                    'quarantineReason' => $ip->quarantine_reason,
                ];
            })->values()->all(),
        ]);
    }
}
