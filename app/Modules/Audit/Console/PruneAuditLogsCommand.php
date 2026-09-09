<?php

declare(strict_types=1);

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A LEJÁRT AUDIT-SOROK törlése (golden rule 10 + GDPR 5(1)(e)).
 *
 * ## Miért van erre egyáltalán parancs
 *
 * Az audit-napló megváltoztathatatlan, és eddig ÖRÖK is volt: a tábla triggere minden
 * DELETE-et elutasított. A security-review ezt jelölte meg — nyers, szabad szöveges PII egy
 * törölhetetlen táblában, retenció nélkül. A napló megváltoztathatatlansága a HAMISÍTÁS
 * ellen véd; a tárolási korlátozás egy másik követelmény, és nem ugyanaz.
 *
 * ## Három egymástól független korlát
 *
 * 1. A parancs a `audit.retention_days` configot használja, és 365 nap alatt MEGÁLL — az
 *    elgépelt env-érték nem eredményez adatvesztést, hanem hibát.
 * 2. A DB-trigger a szándék-jelzést (`ventmail.audit_prune`) ÉS a 365 napos alsó határt
 *    egymástól függetlenül követeli meg — a configot nem is látja.
 * 3. A törlés KÖTEGELT és tranzakcionális: egy több millió soros tábla nem visz el egyetlen
 *    óriási tranzakcióban minden zárat.
 *
 * A `--dry-run` a mérés kedvéért van: az üzemeltető megnézheti, mennyi sor esne ki, mielőtt
 * bármi törlődik.
 */
final class PruneAuditLogsCommand extends Command
{
    protected $signature = 'audit:prune {--dry-run : Csak jelentés, törlés nélkül}';

    protected $description = 'A retenciónál idősebb audit-sorok törlése (min. 365 nap).';

    /** A DB-triggerrel EGYEZŐ alsó határ — a két korlát szándékosan redundáns. */
    public const FLOOR_DAYS = 365;

    /** Egy tranzakcióban ennyi sor. */
    private const BATCH = 500;

    public function handle(): int
    {
        $days = (int) config('audit.retention_days');

        if ($days < self::FLOOR_DAYS) {
            $this->error(sprintf(
                'A retenció (%d nap) az alsó határ (%d nap) alatt van: a friss audit-napló egy '
                .'folyamatban lévő incidensnél a legértékesebb. Semmit nem töröltem.',
                $days,
                self::FLOOR_DAYS
            ));

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $expired = AuditLog::query()->where('created_at', '<', $cutoff)->count();

        if ($this->option('dry-run')) {
            $this->info(sprintf('%d lejárt audit-sor (régebbi mint %s) — dry-run, nem töröltem.', $expired, $cutoff->toDateString()));

            return self::SUCCESS;
        }

        $deleted = 0;

        while (true) {
            $ids = AuditLog::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            /*
             | A `set local` a TRANZAKCIÓRA él, ezért minden köteg saját tranzakció. Ha a
             | tranzakció elmarad, a session-változó elveszik, és a trigger elutasít — a
             | hívási hiba fail-closed, nem csendes törlés.
             */
            $deleted += DB::transaction(static fn (): int => AuditLog::whileRetentionPruning(
                static fn (): int => AuditLog::query()->whereIn('id', $ids)->delete()
            ));
        }

        $this->info(sprintf('%d lejárt audit-sor törölve (retenció: %d nap).', $deleted, $days));

        return self::SUCCESS;
    }
}
