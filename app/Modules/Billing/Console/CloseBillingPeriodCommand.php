<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Services\MonthlyClose;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * HAVI ZÁRÁS (78. kritérium) — a periódus lezárása és a kivonatok elkészítése.
 *
 * ## Miért a PARENTEKET járja végig, nem minden workspace-t
 *
 * Mert a zárás KONSZOLIDÁLT: a `closeConsolidated()` a parentet ÉS a childjeit egyszerre
 * zárja, a child kivonatát `parent_workspace_id`-vel megjelölve. Ha minden workspace-t
 * külön zárnánk, egy child kivonata parent-jelölés NÉLKÜL készülne el (a `close()` nem
 * tudná, hogy sub-account), és a konszolidált számlából kimaradna — a parent kevesebbet
 * fizetne, a child pedig egy számlát kapna, amit nem ő fizet.
 *
 * ## Az ELŐZŐ periódust zárja, nem a folyót
 *
 * Egy futó hónap lezárása azt jelentené, hogy a hónap hátralévő fogyása elveszik (a
 * `Metering` a lezárt periódust kihagyja). A parancs alapból az ELŐZŐ hónapot zárja; a
 * `--period` a kézi/pótló futásra van.
 */
final class CloseBillingPeriodCommand extends Command
{
    protected $signature = 'billing:close {--period= : YYYY-MM, alapból az ELŐZŐ hónap}';

    protected $description = 'A számlázási periódus lezárása és a kivonatok elkészítése (konszolidáltan).';

    public function handle(WorkspaceContext $context, MonthlyClose $close): int
    {
        $period = (string) ($this->option('period') ?: now()->utc()->subMonth()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            $this->error('A periódus alakja `YYYY-MM`.');

            return self::FAILURE;
        }

        $closed = 0;
        $failed = 0;

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            /*
             | CSAK A PARENTEK és az ÖNÁLLÓ workspace-ek: a childokat a
             | `closeConsolidated()` zárja. A `whereDoesntHave('parents')` azt jelenti,
             | hogy „nincs szülője", tehát vagy önálló, vagy ő maga a parent.
             */
            Workspace::query()
                ->withTrashed()
                ->whereDoesntHave('parents')
                ->orderBy('id')
                ->chunkById(50, function ($workspaces) use ($close, $period, &$closed, &$failed): void {
                    foreach ($workspaces as $workspace) {
                        try {
                            $closed += count($close->closeConsolidated($workspace, $period));
                        } catch (Throwable $e) {
                            // EGY TENANT HIBÁJA nem állítja meg a zárást: a többi számlája
                            // nem várhat egy hibás sub-account miatt.
                            $this->warn(sprintf(
                                'A(z) %s zárása elhasalt: %s',
                                (string) $workspace->uuid,
                                $e::class
                            ));

                            $failed++;
                        }
                    }
                });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info("Periódus: {$period}, lezárt kivonat: {$closed}, hibás: {$failed}");

        return self::SUCCESS;
    }
}
