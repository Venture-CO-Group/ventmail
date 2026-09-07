<?php

declare(strict_types=1);

namespace App\Modules\Validation\Jobs;

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Validation\Models\ValidationJob;
use App\Modules\Validation\Models\ValidationResult;
use App\Modules\Validation\Services\AddressValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A BULK futás feldolgozása (41. kritérium).
 *
 * A job CSAK a futás azonosítóját viszi. A címek `pending` sorokként már az adatbázisban
 * vannak, és a haladás is ott van — nem a payloadban.
 *
 * Ez két hibát előz meg egyszerre:
 *
 *  - DUPLIKÁLÁS: a korábbi változat a címeket a payloadban hordozta, és újrapróbáláskor a
 *    TELJES batch újra feldolgozódott. Mérve: `processed=6` egy `total=3` futáson, minden
 *    cím kétszer az exportban, és a ventPay `validations_counter` duplán — HIBÁS SZÁMLA.
 *    Most a feldolgozás a `pending` sorokat FOGYASZTJA: ami már kész, azt nem találja meg.
 *  - MEMÓRIA: százezer cím a payloadban ~6 MB serializálva, chunkonként újra sorba téve.
 *
 * A `$tries = 3` így ártalmatlan: az ismételt futás pontosan ott folytatja, ahol az előző
 * abbahagyta.
 */
final class ProcessValidationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $jobUuid,
    ) {
        $this->afterCommit();

        // A `default` sort egyetlen supervisor sem fogyasztja (a Horizen a küldési
        // prioritás-sorokra van kötve): oda téve a futás örökre `pending` maradna.
        $this->onQueue('validation');
    }

    public function handle(WorkspaceContext $context, AddressValidator $validator): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            $job = ValidationJob::query()->where('uuid', $this->jobUuid)->first();

            if ($job === null || $job->status === 'completed') {
                return;
            }

            $job->forceFill(['status' => 'processing', 'started_at' => $job->started_at ?? now()])->save();

            $pending = ValidationResult::query()
                ->where('validation_job_id', $job->getKey())
                ->where('result', 'pending')
                ->orderBy('id')
                ->limit((int) config('validation.bulk.chunk'))
                ->get();

            foreach ($pending as $row) {
                $verdict = $validator->validate((string) $row->address);

                $row->forceFill([
                    'result' => $verdict->classification,
                    'risk' => $verdict->risk,
                    'reasons' => $verdict->reasons,
                    'suggestion' => $verdict->suggestion,
                ])->save();
            }

            $this->refreshCounters($job);

            // A HÁTRALÉVŐ munkát a sorok mondják meg, nem egy számláló: ez akkor is helyes,
            // ha a job kétszer futott.
            $remaining = ValidationResult::query()
                ->where('validation_job_id', $job->getKey())
                ->where('result', 'pending')
                ->exists();

            if ($remaining) {
                // A KÖVETKEZŐ darab külön jobban: a szonda kerete percenként töltődik, tehát
                // a folyamatos futás úgyis abba ütközne — a lassítás itt FUNKCIÓ.
                self::dispatch($this->workspaceId, $this->jobUuid)->delay(now()->addSeconds(5));
            }
        } catch (Throwable $e) {
            Log::error('A bulk címellenőrzés elhasalt.', ['job' => $this->jobUuid, 'error' => $e::class]);

            throw $e;
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    /**
     * A SZÁMLÁLÓK a sorokból, nem növelésből.
     *
     * A növelés minden újrafutásnál hozzáadna — a `SELECT count(*)` viszont akkor is a
     * valóságot mondja, ha a job háromszor futott.
     */
    private function refreshCounters(ValidationJob $job): void
    {
        $counts = ValidationResult::query()
            ->where('validation_job_id', $job->getKey())
            ->selectRaw('result, count(*) as total')
            ->groupBy('result')
            ->pluck('total', 'result');

        $done = (int) $counts->except('pending')->sum();

        $job->forceFill([
            'processed' => $done,
            'deliverable' => (int) ($counts['deliverable'] ?? 0),
            'risky' => (int) ($counts['risky'] ?? 0),
            'undeliverable' => (int) ($counts['undeliverable'] ?? 0),
            'unknown' => (int) ($counts['unknown'] ?? 0),
            'status' => $done >= (int) $job->total ? 'completed' : 'processing',
            'finished_at' => $done >= (int) $job->total ? now() : null,
        ])->save();
    }
}
