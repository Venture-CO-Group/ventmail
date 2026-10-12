<?php

declare(strict_types=1);

namespace App\Modules\Audience\Jobs;

use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Services\Import\ContactImportProcessor;
use App\Modules\Audience\Services\Import\ImportFileInspector;
use App\Modules\Audience\Services\Import\ImportLimitExceeded;
use App\Modules\Audience\Services\Import\ImportMappingBuilder;
use App\Modules\Audience\Services\Import\ImportUploads;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Az import-fájl FELMÉRÉSE a háttérben (D71): fejléc, minta, sorszám, leképezés-javaslat; a
 * munkalap-váltás újrafelmérése is ez.
 *
 * ## Miért job, és nem a kérés
 *
 * A felmérés az openspout-tal olvassa a fájlt (a sharedStrings-et teljesen), ami egy jól
 * megépített fájlon tetszőlegesen sokáig tarthat — mérve ~50 mp egy 100 MB-os feltöltésen. Három
 * kör pont-javítás után a felmérés elhagyta a webréteget: a kérés csak tárol, rekordot készít és
 * olcsó őröket futtat, az FPM-worker nem foglalható le. A job saját időkorlátot kap (60 mp, a
 * worker-kill `failed()`-je ugyanúgy takarít), és `tries = 1`.
 *
 * A kötegen `inspecting` → `draft` feltételes átmenet; minden hiba a processzor `abandon()`-jával
 * zár (fájl, minta, `failed` státusz).
 */
final class InspectContactImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $importUuid,
        private readonly ?string $sheet = null,
    ) {
        $this->afterCommit();
        $this->onConnection(config('audience.import.connection'));
        $this->onQueue('imports');
    }

    public function handle(
        WorkspaceContext $context,
        ImportFileInspector $inspector,
        ImportUploads $uploads,
        ImportMappingBuilder $mappings,
        ContactImportProcessor $processor,
    ): void {
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

            // ATOMI BEVÉTEL: csak az `inspecting` és még nem futó köteg; a dupla job 0 sort talál.
            $claimed = ContactImport::query()->whereKey($import->getKey())
                ->where('status', ContactImport::STATUS_INSPECTING)->whereNull('phase')
                ->update(['phase' => 'claimed', 'updated_at' => now()]);

            if ($claimed === 0) {
                return;
            }

            $import = $import->fresh();

            try {
                $format = ((array) $import->profile)['format'] ?? ($import->source_type === 'xlsx' ? 'xlsx' : 'csv');
                $profile = $inspector->inspect(
                    $uploads->absolute((string) $import->storage_path),
                    $format,
                    $this->sheet,
                    (int) config('audience.import.inspect_job_budget_seconds'),
                );
            } catch (ImportLimitExceeded $e) {
                $processor->abandon($import, $e->reason);

                return;
            } catch (Throwable $e) {
                $processor->abandon($import, 'error');
                $this->report($e);

                return;
            }

            $fields = ContactField::query()->orderBy('label')->get(['key', 'label'])
                ->map(static fn (ContactField $field): array => ['key' => (string) $field->key, 'label' => (string) $field->label])->all();

            $import->forceFill([
                'profile' => $profile,
                'mapping' => $mappings->initial($profile, $fields),
                'total_rows' => $profile['rows'],
                'phase' => null,
            ])->saveQuietly();

            ContactImport::query()->whereKey($import->getKey())->where('status', ContactImport::STATUS_INSPECTING)
                ->update(['status' => ContactImport::STATUS_DRAFT, 'updated_at' => now()]);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    /** Worker-kill / időtúllépés: ugyanaz a takarítás, mint a hibánál. */
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

            if ($import !== null && $import->status === ContactImport::STATUS_INSPECTING) {
                app(ContactImportProcessor::class)->abandon($import, 'timeout');
            }

            $this->report($e);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    /** Naplózás cím nélkül: csak osztály, SQLSTATE és a köteg azonosítója. */
    private function report(Throwable $e): void
    {
        Log::error('Kontakt-import felmérés hiba', [
            'import' => $this->importUuid,
            'class' => $e::class,
            'sqlstate' => $e instanceof QueryException ? (string) $e->getCode() : null,
        ]);
    }
}
