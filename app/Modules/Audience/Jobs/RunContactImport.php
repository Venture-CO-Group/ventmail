<?php

declare(strict_types=1);

namespace App\Modules\Audience\Jobs;

use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Services\Import\ContactImportProcessor;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Egy kontakt-import ELŐNÉZETÉNEK vagy FUTÁSÁNAK háttérfeladata.
 *
 * A job csak az azonosítókat viszi; a köteg állapota és a haladás az adatbázisban él (a
 * felület ezt kérdezi le). `tries = 1`: az import NEM újrapróbálható — a már beírt chunkok
 * után egy ismételt futás a saját korábbi sorait „létezőnek" látná, és hamis számokat adna.
 * A hibás futás `failed` állapotba kerül, és új feltöltéssel indítható újra.
 */
final class RunContactImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $importUuid,
        private readonly bool $commit,
    ) {
        $this->afterCommit();
        $this->onConnection(config('audience.import.connection'));
        $this->onQueue('imports');
    }

    public function handle(WorkspaceContext $context, ContactImportProcessor $processor): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;
        $context->set($workspace);

        try {
            $import = ContactImport::query()->where('uuid', $this->importUuid)->first();

            if ($import === null) {
                return;
            }

            $expected = $this->commit ? ContactImport::STATUS_QUEUED : ContactImport::STATUS_PREVIEWING;

            // ATOMI BEVETEL: csak az az egy worker fut, amelyik az állapotot átviszi. Egy
            // kétszer kiadott job (újraindítás, dupla kattintás) a másodiknál 0 sort talál.
            $claimed = ContactImport::query()
                ->whereKey($import->getKey())
                ->where('status', $expected)
                ->update(['status' => $this->commit ? ContactImport::STATUS_PROCESSING : ContactImport::STATUS_PREVIEWING, 'updated_at' => now()]);

            if ($claimed === 0) {
                return;
            }

            $processor->run($import->fresh(), $this->commit);
        } catch (Throwable $e) {
            // A processzor már `failed`-re állította a köteget; itt csak naplózunk, és NEM
            // dobunk tovább: az újrapróbálás tilos (lásd az osztály docblockját).
            Log::error('Kontakt-import hiba', ['import' => $this->importUuid, 'exception' => $e]);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    /** A worker kilőtte (timeout, memória): a köteg ne maradjon örökre „fut” állapotban. */
    public function failed(Throwable $e): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $context = app(WorkspaceContext::class);
        $previous = $context->hasWorkspace() ? $context->workspace() : null;
        $context->set($workspace);

        try {
            $import = ContactImport::query()->where('uuid', $this->importUuid)->first();

            if ($import !== null && ! $import->finished()) {
                $import->forceFill([
                    'status' => ContactImport::STATUS_FAILED,
                    'failure_reason' => 'worker',
                    'completed_at' => now(),
                ])->saveQuietly();
            }

            Log::error('Kontakt-import megszakadt', ['import' => $this->importUuid, 'exception' => $e]);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }
}
