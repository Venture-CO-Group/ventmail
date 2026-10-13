<?php

declare(strict_types=1);

namespace App\Modules\Audience\Console;

use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Services\Import\ImportUploads;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Az import-fájlok MEGŐRZÉSI idejének betartatása (golden rule 10, v1.1).
 *
 * A feltöltött fájl az ügyfél címlistája — személyes adatok tömege, és a feldolgozás után nincs
 * jogalapunk megőrizni. A sikeres futás maga törli (`ContactImportProcessor::finish`); ez a
 * parancs a MARADÉKOT takarítja:
 *
 *  - a félbehagyott (`draft`, `previewing`, `previewed`) és a sikertelen kötegek fájlját és
 *    rekordját — a varázsló elhagyása nem hagyhat örök nyomot;
 *  - a lezárt kötegek lejárt HIBARIPORTJÁT — a rekord (jogalap, forrás, darabszámok) az
 *    audit része, az megmarad.
 *
 * A futó (`queued`, `processing`) kötegekhez soha nem nyúl.
 */
final class PurgeImportsCommand extends Command
{
    protected $signature = 'audience:purge-imports';

    protected $description = 'A lejárt import-fájlok és hibariportok törlése.';

    public function handle(WorkspaceContext $context, ImportUploads $uploads): int
    {
        $drafts = 0;
        $reports = 0;
        $cutoff = now()->subDays((int) config('audience.import.retention_days'));

        try {
            Workspace::query()->withTrashed()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, $uploads, $cutoff, &$drafts, &$reports): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    // Az 1 óránál régebbi `inspecting` elakadt (a felmérés 60 mp-es): fájl + rekord törlése, nem
                    // kell hetekig várni a megőrzési időre.
                    ContactImport::query()
                        ->where('status', ContactImport::STATUS_INSPECTING)
                        ->where('updated_at', '<=', now()->subHour())
                        ->each(function (ContactImport $import) use ($uploads, &$drafts): void {
                            $uploads->delete($import->storage_path);
                            $import->delete();
                            $drafts++;
                        });

                    ContactImport::query()
                        ->whereIn('status', [ContactImport::STATUS_DRAFT, ContactImport::STATUS_INSPECTING, ContactImport::STATUS_PREVIEWING, ContactImport::STATUS_PREVIEWED, ContactImport::STATUS_FAILED])
                        ->where('updated_at', '<=', $cutoff)
                        ->each(function (ContactImport $import) use ($uploads, &$drafts): void {
                            $uploads->delete($import->storage_path);
                            $uploads->delete($import->error_report_path);

                            // A sikertelen köteg rekordja az audit része — csak a fájlokat töröljük;
                            // a félbehagyott draftnak viszont nincs mit őriznie.
                            if ($import->status === ContactImport::STATUS_FAILED) {
                                // A mintasorok (valódi címek) a sikertelen rekordon sem maradhatnak.
                                $profile = (array) $import->profile;
                                unset($profile['sample']);
                                $stats = (array) $import->stats;
                                $stats['samples'] = [];

                                $import->forceFill(['storage_path' => null, 'error_report_path' => null, 'profile' => $profile, 'stats' => $stats])->saveQuietly();
                            } else {
                                $import->delete();
                            }

                            $drafts++;
                        });

                    ContactImport::query()
                        ->where('status', ContactImport::STATUS_COMPLETED)
                        ->whereNotNull('error_report_path')
                        // A hiányzó `completed_at`-et (modellt megkerülő írás) az `updated_at` pótolja.
                        ->where(fn ($query) => $query
                            ->where('completed_at', '<=', $cutoff)
                            ->orWhere(fn ($missing) => $missing->whereNull('completed_at')->where('updated_at', '<=', $cutoff)))
                        ->each(function (ContactImport $import) use ($uploads, &$reports): void {
                            $uploads->delete($import->error_report_path);
                            $import->forceFill(['error_report_path' => null])->saveQuietly();
                            $reports++;
                        });

                    $this->sweepOrphans($uploads, (int) $workspace->id, $cutoff);
                }
            });
        } finally {
            $context->forget();
        }

        $this->info("Törölve — félbehagyott import: {$drafts}, lejárt hibariport: {$reports}");

        return self::SUCCESS;
    }

    /**
     * ÁRVA fájlok: a `imports/{workspace}/` könyvtár lejárt fájljai, amelyekre egyetlen rekord sem
     * hivatkozik (a felmérés közben megszakadt feltöltés maradéka). A hivatkozottat és az újat
     * nem bántja.
     */
    private function sweepOrphans(ImportUploads $uploads, int $workspaceId, Carbon $cutoff): void
    {
        $disk = $uploads->disk();
        $referenced = [];

        ContactImport::query()->get(['storage_path', 'error_report_path'])->each(function (ContactImport $import) use (&$referenced): void {
            foreach ([$import->storage_path, $import->error_report_path] as $path) {
                if ($path !== null) {
                    $referenced[$path] = true;
                }
            }
        });

        foreach ($disk->files('imports/'.$workspaceId) as $file) {
            if (! isset($referenced[$file]) && $disk->lastModified($file) <= $cutoff->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }
}
