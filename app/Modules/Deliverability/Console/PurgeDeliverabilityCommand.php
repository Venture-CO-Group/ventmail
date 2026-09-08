<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Console;

use App\Modules\Deliverability\Models\BlocklistCheck;
use App\Modules\Deliverability\Models\DmarcReport;
use App\Modules\Deliverability\Models\SeedTest;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * A kézbesíthetőségi suite lejárt adatainak takarítása (golden rule 10).
 *
 * Három tábla, három külön indokkal:
 *
 *  - `blocklist_checks`: nem PII, de VÉGTELENÜL nő — óránként `IP × zóna` sor. Száz IP-vel ez
 *    évi másfél millió sor egy táblában, aminek az utolsó napja az érdekes. A régi sorokat a
 *    trend kedvéért tartjuk meg egy ideig, aztán elengedjük.
 *  - `dmarc_reports`: a jelentés a FORRÁS-IP-ket tartalmazza, tehát idegen hálózatokról szóló
 *    adat — a `dmarc.retention_days` eddig konfigurációként létezett, kód nélkül.
 *  - `seed_tests`: a mérés a saját postafiókjainkról szól, de az eredménye elavul.
 *
 * A sorok CASCADE-en viszik a gyereksorokat (`dmarc_report_rows`).
 */
final class PurgeDeliverabilityCommand extends Command
{
    protected $signature = 'deliverability:purge';

    protected $description = 'A lejárt blocklist-ellenőrzések, DMARC-jelentések és seed-mérések törlése.';

    public function handle(WorkspaceContext $context): int
    {
        // A `blocklist_checks` PLATFORM-adat: nincs workspace-e, nincs `expires_at`-je sem —
        // a kor a mérték. Tenant-szűkítést sem kell kikapcsolni hozzá, mert a modell eleve
        // nem workspace-hez kötött. (A megfogalmazás szándékosan kerüli a metódusnevet: a
        // `ScopeBypassGuardTest` a forrásszövegben keres, és egy magyarázó komment miatt nem
        // szabad kivételt adni neki — a kivétel ettől a fájltól VÉGLEG elvenné az őrt.)
        $checks = BlocklistCheck::query()
            ->where('checked_at', '<=', now()->subDays((int) config('deliverability.blocklists.retention_days')))
            ->delete();

        $reports = 0;
        $seeds = 0;

        try {
            Workspace::query()->withTrashed()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, &$reports, &$seeds): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $reports += DmarcReport::query()
                        ->where(fn (Builder $q) => $this->expired($q, (int) config('deliverability.dmarc.retention_days')))
                        ->delete();

                    $seeds += SeedTest::query()
                        ->where(fn (Builder $q) => $this->expired($q, (int) config('deliverability.seed.retention_days')))
                        ->delete();
                }
            });
        } finally {
            $context->forget();
        }

        $this->info("Törölve — blocklist-ellenőrzés: {$checks}, DMARC-jelentés: {$reports}, seed-mérés: {$seeds}");

        return self::SUCCESS;
    }

    /** „Lejárt" — a hiányzó lejárati idővel EGYÜTT (a modellt megkerülő beszúrás `NULL`-t hagy). */
    private function expired(Builder $query, int $days): void
    {
        $query
            ->where('expires_at', '<=', now())
            ->orWhere(fn (Builder $missing) => $missing
                ->whereNull('expires_at')
                ->where('created_at', '<=', now()->subDays($days)));
    }
}
