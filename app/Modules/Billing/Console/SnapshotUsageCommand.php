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

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            Workspace::query()->orderBy('id')->chunkById(50, function ($workspaces) use ($context, $metering, &$measured): void {
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

                        // A DEDIKÁLT IP-k: pillanatkép, nem összeg.
                        $metering->snapshot(UsageDimension::DedicatedIps, IpAddress::query()->count());

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
                            'A(z) %s mérése elhasalt: %s',
                            (string) $workspace->uuid,
                            $e::class
                        ));
                    }
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info("Mért workspace: {$measured}");

        return self::SUCCESS;
    }
}
