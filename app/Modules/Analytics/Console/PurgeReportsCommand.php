<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Console;

use App\Modules\Analytics\Contracts\ReportArtifactStore;
use App\Modules\Analytics\Models\AnalyticsReport;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * A LEJÁRT riport-PDF-ek törlése (óránként, v1.1 · 8. modul). A FÁJL törlődik, a sor `expired`
 * állapotban MARAD (auditnyom: ki, mikor, milyen paraméterrel kért riportot). Idempotens: a már
 * lejárt sort nem érinti, egy hiányzó fájl nem hiba. Workspace-enként, a tenant-kontextuson
 * belül fut (global scope; nincs scope-megkerülés).
 */
final class PurgeReportsCommand extends Command
{
    protected $signature = 'analytics:purge-reports';

    protected $description = 'A lejárt PDF-riportok fájljainak törlése (a sor expired-ként marad).';

    public function handle(WorkspaceContext $context, ReportArtifactStore $store): int
    {
        $expired = 0;
        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            Workspace::query()->withTrashed()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, $store, &$expired): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    AnalyticsReport::query()
                        ->where('status', 'ready')
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now())
                        ->orderBy('id')
                        ->each(function (AnalyticsReport $report) use ($store, &$expired): void {
                            if ($report->file_path !== null) {
                                $store->delete((string) $report->file_path);
                            }

                            $report->forceFill(['status' => 'expired', 'file_path' => null])->save();
                            $expired++;
                        });
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info(sprintf('Lejárt riport: %d', $expired));

        return self::SUCCESS;
    }
}
