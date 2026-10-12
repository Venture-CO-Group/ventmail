<?php

declare(strict_types=1);

namespace App\Modules\Audience\Jobs;

use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Services\Import\ContactImportProcessor;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
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

            // ATOMI BEVÉTEL — mindkét irányban feltételes UPDATE, pontosan egy workerre ad 1-et:
            //  - futás: `queued` → `processing`;
            //  - előnézet: `previewing` ÉS még nem fut (`phase IS NULL`) → `phase = claimed`.
            // (A korábbi `previewing → previewing` „átmenet” nem zárt ki semmit.)
            $claimed = $this->commit
                ? ContactImport::query()->whereKey($import->getKey())->where('status', ContactImport::STATUS_QUEUED)
                    ->update(['status' => ContactImport::STATUS_PROCESSING, 'updated_at' => now()])
                : ContactImport::query()->whereKey($import->getKey())->where('status', ContactImport::STATUS_PREVIEWING)->whereNull('phase')
                    ->update(['phase' => 'claimed', 'updated_at' => now()]);

            if ($claimed === 0) {
                return;
            }

            $processor->run($import->fresh(), $this->commit);
        } catch (Throwable $e) {
            // A processzor már `failed`-re állította a köteget; itt csak naplózunk, és NEM
            // dobunk tovább: az újrapróbálás tilos (lásd az osztály docblockját).
            $this->report('Kontakt-import hiba', $e);
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

            $this->report('Kontakt-import megszakadt', $e);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    /**
     * NAPLÓZÁS CÍM NÉLKÜL: a `QueryException` üzenete és kötései a beszúrt értékeket (címeket,
     * neveket) hordozzák, a kivétel-objektum a stack-trace argumentumaival együtt. Ezért csak az
     * osztály, az SQLSTATE és a köteg azonosítója kerül a naplóba.
     */
    private function report(string $message, Throwable $e): void
    {
        Log::error($message, [
            'import' => $this->importUuid,
            'class' => $e::class,
            'sqlstate' => $e instanceof QueryException ? (string) $e->getCode() : null,
        ]);
    }
}
