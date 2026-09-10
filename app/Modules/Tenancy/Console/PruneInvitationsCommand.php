<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Models\WorkspaceInvitation;
use Illuminate\Console\Command;

/**
 * A BEVÁLTOTT ÉS LEJÁRT MEGHÍVÓK TAKARÍTÁSA (golden rule 10 · GDPR).
 *
 * A meghívó olyan ember e-mail-címét tárolja, aki lehet, hogy sosem lesz felhasználó, és
 * aki nem járult hozzá semmihez — ez a legkevésbé indokolható PII a rendszerben. Örökre
 * megtartani nem lehet, viszont a tagság KELETKEZÉSÉNEK bizonyítéka nem is itt van: az
 * append-only audit-naplóban (`member.invited`, `member.invitation.redeemed`), ahol saját
 * retenciós szabály érvényes.
 *
 * A NYITOTT, LE NEM JÁRT meghívókhoz nem nyúlunk: azok élő hozzáférési döntések.
 */
final class PruneInvitationsCommand extends Command
{
    protected $signature = 'tenancy:prune-invitations {--dry-run : csak számol, nem töröl}';

    protected $description = 'Beváltott és lejárt meghívók törlése a retenciós határ után.';

    public function handle(): int
    {
        $days = max(1, (int) config('tenancy.invitations.retention_days', 90));
        $cutoff = now()->subDays($days);

        /*
         | GLOBAL SCOPE NÉLKÜL, SZÁNDÉKOSAN: a takarítás MINDEN tenantra fut, konzolból,
         | ahol nincs (és nem is lehet) workspace-kontextus. A feltétel szűk és idő-alapú —
         | nem olvas ki adatot, csak törli a lejárt nyomot.
         */
        $query = WorkspaceInvitation::query()
            ->withoutGlobalScopes()
            ->where(fn ($q) => $q
                ->where('accepted_at', '<', $cutoff)
                ->orWhere(fn ($inner) => $inner->whereNull('accepted_at')->where('expires_at', '<', $cutoff)));

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Törölhető meghívó: {$count} (retenció: {$days} nap).");

            return self::SUCCESS;
        }

        $query->delete();

        $this->info("Törölt meghívó: {$count} (retenció: {$days} nap).");

        return self::SUCCESS;
    }
}
