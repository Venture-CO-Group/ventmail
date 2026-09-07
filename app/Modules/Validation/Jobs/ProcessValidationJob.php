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
 * DARABOKBAN megy, és minden darab után menti az állapotot. Egy százezer soros lista
 * feldolgozása percekig tart; ha a worker közben újraindul, a munka NEM kezdődik elölről —
 * a `processed` mutatja, hol tartunk.
 *
 * A darabolás nem csak a megbízhatóságról szól: a szonda per-domain kerete percenként
 * töltődik, tehát a folyamatos futás úgyis a keretbe ütközne. A lassítás itt FUNKCIÓ.
 */
final class ProcessValidationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  list<string>  $addresses  a hátralévő címek
     */
    public function __construct(
        private readonly int $workspaceId,
        private readonly string $jobUuid,
        private readonly array $addresses,
    ) {
        $this->afterCommit();
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

            $chunk = (int) config('validation.bulk.chunk');
            $batch = array_slice($this->addresses, 0, $chunk);
            $rest = array_slice($this->addresses, $chunk);

            $counts = ['deliverable' => 0, 'risky' => 0, 'undeliverable' => 0, 'unknown' => 0];

            foreach ($batch as $address) {
                $verdict = $validator->validate((string) $address);

                ValidationResult::query()->create([
                    'validation_job_id' => $job->getKey(),
                    'address' => $verdict->email,
                    'result' => $verdict->classification,
                    'risk' => $verdict->risk,
                    'reasons' => $verdict->reasons,
                    'suggestion' => $verdict->suggestion,
                ]);

                $counts[$verdict->classification]++;
            }

            $job->forceFill([
                'processed' => (int) $job->processed + count($batch),
                'deliverable' => (int) $job->deliverable + $counts['deliverable'],
                'risky' => (int) $job->risky + $counts['risky'],
                'undeliverable' => (int) $job->undeliverable + $counts['undeliverable'],
                'unknown' => (int) $job->unknown + $counts['unknown'],
                'status' => $rest === [] ? 'completed' : 'processing',
                'finished_at' => $rest === [] ? now() : null,
            ])->save();

            if ($rest !== []) {
                // A KÖVETKEZŐ darab külön jobban: a szonda kerete percenként töltődik, tehát
                // a folyamatos futás úgyis abba ütközne.
                self::dispatch($this->workspaceId, $this->jobUuid, $rest)->delay(now()->addSeconds(5));
            }
        } catch (Throwable $e) {
            Log::error('A bulk címellenőrzés elhasalt.', ['job' => $this->jobUuid, 'error' => $e::class]);

            ValidationJob::query()
                ->where('uuid', $this->jobUuid)
                ->update(['status' => 'failed', 'reason' => 'PROCESSING_FAILED']);

            throw $e;
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }
}
