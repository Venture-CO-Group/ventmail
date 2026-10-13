<?php

declare(strict_types=1);

namespace App\Modules\Audience\Console;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A `contacts.source` VISSZATÖLTÉSE (v1.1 · 5. modul, review RS5) — expand-only adat-job.
 *
 * A v1.1 előtti kontaktoknál a forrás NULL, és a dashboard / szegmens a marketing-consent
 * forrását egy korrelált allekérdezéssel keresi. A visszatöltés ugyanazt a kifejezést írja be
 * EGYSZER (`SegmentEngine::SOURCE_EXPRESSION`), kötegenként (`id` szerint, alapból 2000), így a
 * későbbi aggregátum már csak az oszlopot olvassa.
 *
 * Workspace-enként, a saját kontextusában fut (a `Contact` global scope-ja szűr); a meglévő
 * értéket nem írja felül (`whereNull`), tehát ismételten is biztonságos. Az ütemező naponta
 * futtatja — amikor nincs NULL sor, egy üres lekérdezés.
 */
final class BackfillContactSourcesCommand extends Command
{
    protected $signature = 'audience:backfill-sources {--chunk=2000 : kötegméret}';

    protected $description = 'A v1.1 előtti kontaktok feliratkozási forrásának kitöltése (expand-only).';

    public function handle(WorkspaceContext $context): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $filled = 0;
        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            Workspace::query()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, $chunk, &$filled): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    Contact::query()->whereNull('source')->select('id')->chunkById($chunk, function ($rows) use (&$filled): void {
                        $filled += Contact::query()
                            ->whereIn('id', $rows->pluck('id'))
                            ->whereNull('source')
                            ->update(['source' => DB::raw(SegmentEngine::SOURCE_EXPRESSION)]);
                    });
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info("Kitöltve: {$filled}");

        return self::SUCCESS;
    }
}
