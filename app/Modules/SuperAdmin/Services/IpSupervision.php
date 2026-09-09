<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * IP-POOL ÉS WARMUP FELÜGYELET (87. kritérium).
 *
 * ## Miért PLATFORM-művelet, és nem tenant-művelet
 *
 * Mert a küldő IP a MI infrastruktúránk: egy megosztott poolon több tenant küld, és egy
 * karanténba tett IP MINDEGYIKÜK forgalmát átirányítja. Ha egy tenant dönthetne róla, a
 * saját kampányáért kivehetne a forgalomból egy IP-t, amit más tenantok is használnak.
 *
 * ## A KARANTÉN INDOKKAL jár
 *
 * A karantén a legdurvább IP-művelet: az IP kiesik a forgalomból, a maradék IP-kre nagyobb
 * volumen jut, és warmup alatt ez reputáció-vesztést okozhat. Egy fél évvel későbbi
 * vizsgálatnál („miért esett le a kézbesítési arányunk márciusban?") az indok az, ami
 * megválaszolja a kérdést.
 *
 * ## A FELOLDÁS a súlyosabb művelet
 *
 * A karanténba tétel VÉDELEM (egy beteg IP nem küld tovább); a feloldás viszont ÁLLÍTÁS:
 * „ez az IP megint küldhet". Ha ez indoklás nélkül megtehető lenne, egy blocklistán lévő
 * IP visszakerülhetne a forgalomba anélkül, hogy bárki megnézte volna, lekerült-e a
 * listáról.
 */
final class IpSupervision
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException|InvalidArgumentException
     */
    public function quarantine(User $admin, IpAddress $ip, string $reason): IpAddress
    {
        $this->authorize($admin);

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException(
                'A karanténhoz INDOK kötelező: az IP kiesik a forgalomból, és a maradékra '
                .'nagyobb volumen jut — warmup alatt ez reputáció-vesztés.'
            );
        }

        $ip->forceFill([
            'status' => 'quarantined',
            'quarantined_at' => now(),
            'quarantine_reason' => mb_substr($reason, 0, 255),
        ])->save();

        $this->audit->log(
            'platform.ip_quarantined',
            $ip,
            context: [
                'address' => (string) $ip->address,
                'reason' => $ip->quarantine_reason,
                'admin_id' => (int) $admin->getKey(),
            ],
            // A DEDIKÁLT (tenant-tulajdonú) IP karanténja a TENANT ügye: a
            // kézbesíthetősége esik le tőle, és látnia kell, miért. Megosztott
            // platform-poolnál nincs egyetlen érintett tenant — ott a sor platform-szintű.
            workspaceId: $this->poolWorkspaceId($ip) ?? AuditLogger::NO_WORKSPACE,
            actor: $admin,
        );

        return $ip->fresh();
    }

    /**
     * @throws AuthorizationException|InvalidArgumentException
     */
    public function release(User $admin, IpAddress $ip, string $reason): IpAddress
    {
        $this->authorize($admin);

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException(
                'A feloldáshoz INDOK kötelező: a feloldás ÁLLÍTÁS („ez az IP megint '
                .'küldhet"), nem védelem — enélkül egy blocklistán lévő IP visszakerülhetne.'
            );
        }

        $ip->forceFill([
            'status' => 'active',
            'quarantined_at' => null,
            'quarantine_reason' => null,
        ])->save();

        $this->audit->log(
            'platform.ip_released',
            $ip,
            context: [
                'address' => (string) $ip->address,
                'reason' => mb_substr($reason, 0, 255),
                'admin_id' => (int) $admin->getKey(),
            ],
            workspaceId: $this->poolWorkspaceId($ip) ?? AuditLogger::NO_WORKSPACE,
            actor: $admin,
        );

        return $ip->fresh();
    }

    /**
     * A WARMUP-ÁLLAPOT áttekintése — pool-onként, IP-nként.
     *
     * A `daily_limit` és a `sent_today` együtt mutatja meg, hol tart a warmup: a puszta
     * „hányadik napon vagyunk" szám félrevezető, mert egy alacsony forgalmú nap nem
     * hitelesíti az IP-t a fogadóknál. Ami számít, az az ELKÜLDÖTT volumen.
     *
     * @return list<array<string, mixed>>
     */
    public function warmupOverview(User $admin): array
    {
        $this->authorize($admin);

        /*
         | A POOLOK TENANTEKEN ÁTNYÚLVA — és ez a platform-admin egyetlen scope-kivétele itt.
         |
         | Az `IpAddress` SZÁNDÉKOSAN nem workspace-kötött (a küldő IP a MI
         | infrastruktúránk), a `IpPool` viszont tenant- VAGY platform-szintű: kontextus
         | nélkül a `WorkspaceOrPlatformScope` fail-closed módon semmit nem ad. A
         | platform-admin épp azért kérdez, hogy MINDEN pool állapotát lássa — egy
         | üzemeltetői felület, ami csak az „aktuális tenant" pooljait mutatja, nem
         | üzemeltetői felület.
         |
         | A poolokat KÜLÖN lekérdezéssel töltjük be, `keyBy`-jal indexelve: így az
         | eager-load helyett egyetlen extra lekérdezés van, és a scope-kikapcsolás
         | pontosan EGY sorra korlátozódik — nem az egész bejáráson él.
         */
        $pools = IpPool::query()->withoutGlobalScopes()->get()->keyBy('id');

        return IpAddress::query()
            ->orderBy('ip_pool_id')
            ->orderBy('address')
            ->get()
            ->map(fn (IpAddress $ip): array => [
                'address' => (string) $ip->address,
                'hostname' => (string) $ip->hostname,
                'pool' => (string) $pools[$ip->ip_pool_id]?->name,
                'pool_type' => (string) $pools[$ip->ip_pool_id]?->type,
                // A POOL TENANTJA: `null` a megosztott platform-poolnál.
                'workspace_id' => $pools[$ip->ip_pool_id]?->workspace_id === null
                    ? null
                    : (int) $pools[$ip->ip_pool_id]->workspace_id,
                'status' => (string) $ip->status,
                'warmup_state' => (string) $ip->warmup_state,
                'daily_limit' => (int) $ip->daily_limit,
                'sent_today' => (int) $ip->sent_today,
                // A KIHASZNÁLTSÁG az, amit egy üzemeltető ránézésre keres: a warmup-görbe
                // betartása vagy a limit súrolása.
                'utilisation' => $ip->daily_limit > 0
                    ? round((int) $ip->sent_today / (int) $ip->daily_limit * 100, 1)
                    : null,
                'reputation_score' => $ip->reputation_score === null ? null : (float) $ip->reputation_score,
                'quarantined_at' => $ip->quarantined_at?->toIso8601String(),
                'quarantine_reason' => $ip->quarantine_reason,
            ])
            ->all();
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $admin): void
    {
        if (! Gate::forUser($admin)->allows('platform.admin')) {
            // AZ ELUTASÍTOTT PRÓBÁLKOZÁS IS AUDITÁLT (81. kritérium).
            $this->audit->log(
                'platform.denied',
                null,
                context: ['operation' => 'ip_supervision', 'admin_id' => (int) $admin->getKey()],
                workspaceId: AuditLogger::NO_WORKSPACE,
                actor: $admin,
            );

            throw new AuthorizationException('Az IP-felügyelet platform-admin jogosultságot igényel.');
        }
    }

    /**
     * A POOL TENANTJA — `null` a megosztott platform-poolnál.
     *
     * A scope-kikapcsolás indoka ugyanaz, mint a `warmupOverview()`-nál: a platform-admin
     * kontextus nélkül dolgozik, a `IpPool` viszont tenant- VAGY platform-szintű, tehát a
     * `WorkspaceOrPlatformScope` fail-closed módon semmit nem adna — és akkor egy dedikált
     * IP karanténja a tenant naplójából kimaradna. A lekérdezés elsődleges kulcsra megy, és
     * EGYETLEN oszlopot olvas: tenant-tartalmat nem.
     */
    private function poolWorkspaceId(IpAddress $ip): ?int
    {
        $workspaceId = IpPool::query()
            ->withoutGlobalScopes()
            ->whereKey($ip->ip_pool_id)
            ->value('workspace_id');

        return $workspaceId === null ? null : (int) $workspaceId;
    }
}
