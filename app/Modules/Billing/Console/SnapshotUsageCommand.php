<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Audience\Models\Contact;
use App\Modules\Billing\Enums\UsageDimension;
use App\Modules\Billing\Services\Metering;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * A CSÚCS- ÉS PILLANATKÉP-DIMENZIÓK mérése (75. kritérium).
 *
 * ## Miért ütemezett parancs, és nem esemény
 *
 * Mert ezek a dimenziók ÁLLAPOTOK, nem események. A `contacts_peak` a közönség mérete —
 * amit egy import, egy űrlap-beküldés, egy GDPR-törlés és egy szegmens-művelet is
 * megváltoztat; ha minden ilyen ponton mérnénk, a mérés a hívási helyeken szóródna szét, és
 * valamelyik kimaradna. A `dedicated_ips` és a `log_retention_tier` szintén állapot.
 *
 * ## A NAPI GYAKORISÁG elég, és miért
 *
 * A `contacts_peak` a periódus MAXIMUMÁT tartja: egy napon belüli csúcs-visszaesés
 * (import, majd azonnali tisztítás) elvileg kimaradhat. Ez tudatosan vállalt közelítés —
 * a számlázás egysége a hónap, és egy napos felbontású csúcs a tenant javára téved. A
 * gyakoribb mérés az adatbázist terhelné anélkül, hogy a SZÁMLA pontosabb lenne.
 */
final class SnapshotUsageCommand extends Command
{
    protected $signature = 'billing:snapshot';

    protected $description = 'A csúcs- és pillanatkép-dimenziók mérése (kontaktok, dedikált IP-k, retenciós tier).';

    public function handle(WorkspaceContext $context, Metering $metering): int
    {
        $measured = 0;
        $failed = 0;

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            /*
             | A `withTrashed()` a ZÁRÓ PARANCCSAL egyezik, és ez nem apróság.
             |
             | A review megmérte: a pillanatkép a SoftDeletes scope-pal futott, a zárás
             | `withTrashed()`-del — tehát egy 5-én törölt workspace-nek EGYETLEN fogyás-sora
             | sem lett, kivonata viszont készült. Az 5-ei `dedicated_ips`/
             | `log_retention_tier` értékkel az egész hónapra ki lett volna számlázva, a
             | `contacts_peak`-je pedig 5-én megállt.
             |
             | A HELYES viselkedés a törölt workspace MÉRÉSE a periódus végéig: az
             | offboarding hónapjában a tárolás és az IP-k még a miénket terhelték, és a
             | tenant a hónap közepéig valóban használta a rendszert. A törlés utáni
             | napokon a mért érték magától nullára esik (nincs kontakt, nincs pool).
             */
            Workspace::query()->withTrashed()->orderBy('id')->chunkById(50, function ($workspaces) use ($context, $metering, &$measured, &$failed): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    try {
                        /*
                         | A KÖZÖNSÉG MÉRETE: csak az ELÉRHETŐ kontaktok.
                         |
                         | A soft-deleted (GDPR-törölt) kontakt nem küldhető cím, tehát nem
                         | is fizetendő — a `Contact` SoftDeletes scope-ja ezt automatikusan
                         | kizárja. Ugyanaz a döntés, mint a B4 `ListAnalytics::summary()`-ben:
                         | ott a `withTrashed()` mérve 3-at mondott 2 helyett, és a
                         | metering ezen a számon TÚLSZÁMLÁZOTT volna.
                         */
                        $metering->observePeak(UsageDimension::ContactsPeak, Contact::query()->count());

                        /*
                         | A DEDIKÁLT IP-k — a TENANT SAJÁT poolján át, nem platform-szinten.
                         |
                         | Az `IpAddress` SZÁNDÉKOSAN nem workspace-hez kötött (a modell
                         | docblockja ezt kimondja: a küldő IP a MI infrastruktúránk), tehát
                         | nincs rajta global scope — és a puszta `IpAddress::count()`
                         | PLATFORM-SZINTŰ. A review ezt megmérte: A-tenant 1 dedikált IP,
                         | B-tenant 2, megosztott platform-pool 4 → MINDKETTŐ
                         | `dedicated_ips = 7` lett. Vagyis minden tenant kifizette a teljes
                         | IP-készletet, beleértve a megosztott poolt, amiért senki nem fizet
                         | külön — és ez a hat dimenzió közül a legdrágább tétel.
                         |
                         | A tenant-kötés a POOLON van (`ip_pools.workspace_id`), és csak a
                         | `dedicated` típus fizetendő: a megosztott pool a platform
                         | alapszolgáltatása, ami a csomag árában van.
                         */
                        $metering->snapshot(UsageDimension::DedicatedIps, IpAddress::query()
                            ->whereHas('pool', fn ($pool) => $pool
                                ->where('workspace_id', $workspace->getKey())
                                ->where('type', 'dedicated'))
                            ->count());

                        // A RETENCIÓS TIER: a jelenlegi beállítás napokban. A plan-függő
                        // értéket a ventPay adja majd; addig a config a forrás.
                        $metering->snapshot(
                            UsageDimension::LogRetentionTier,
                            (int) config('sending.delivery_attempt_retention_days', 90),
                        );

                        $measured++;
                    } catch (Throwable $e) {
                        // EGY TENANT HIBÁJA nem állítja meg a mérést: a többi fogyása nem
                        // veszhet el egy hibás workspace miatt.
                        $this->warn(sprintf(
                            'A(z) %s mérése elhasalt: %s — %s',
                            (string) $workspace->uuid,
                            $e::class,
                            $e->getMessage()
                        ));

                        report($e);

                        $failed++;
                    }
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info("Mért workspace: {$measured}, hibás: {$failed}");

        // A HIBÁS MÉRÉS NEM SIKER: egy kimaradt `contacts_peak` a hónap végén alul- vagy
        // felülszámlázás, és az ütemezőnek tudnia kell róla.
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
