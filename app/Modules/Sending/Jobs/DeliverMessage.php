<?php

declare(strict_types=1);

namespace App\Modules\Sending\Jobs;

use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\MessageDispatcher;
use App\Modules\Sending\Services\RetryPolicy;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Egy üzenet kiküldése a sorból.
 *
 * A JOB NEM VISZI MAGÁVAL A TARTALMAT: csak az uuid-t és a workspace-t. Egy sor-payload
 * Redisben él, több példányban, és a `failed_jobs` táblába is bekerül — a levéltörzs ott
 * magic-linket és tokent hordozna. Az adat az adatbázisban van, a job csak hivatkozik rá.
 */
final class DeliverMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // az újrapróbálást a RetryPolicy vezérli, nem a queue-worker

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $messageUuid,
    ) {}

    public function handle(MessageDispatcher $dispatcher, WorkspaceContext $context, RetryPolicy $retry): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            $message = Message::query()->where('uuid', $this->messageUuid)->first();

            if ($message === null) {
                return;
            }

            $dispatcher->dispatch($message);
        } catch (Throwable $e) {
            // A dispatcher már `deferred`-re állította az üzenetet; itt csak az
            // ÚJRAPRÓBÁLÁS idejét írjuk fel, hogy az ütemező tudja, mikor veheti elő.
            $this->scheduleRetry($retry);

            report($e);
        } finally {
            /*
             | A HÍVÓ KONTEXTUSÁT VISSZAADJUK — nem eldobjuk.
             |
             | Hosszan élő workerben a bent felejtett kontextus a KÖVETKEZŐ job levelét
             | idegen tenantnak könyvelné el, ezért törölni KELL, ha nem volt korábbi. De ha
             | volt, akkor ez a job egy hívási lánc KÖZEPÉN fut — és mérve pontosan ez
             | történt: `SENDING_DISPATCH_VIA_QUEUE=true` + `sync` queue mellett a
             | `JourneyEngine` inline hívta ezt a jobot, a `forget()` elvitte a motor
             | kontextusát, és a journey-futás az első levél után `exited`-ként lezárult —
             | a 2. és 3. levél SOHA nem ment ki. A `phpunit.xml` épp ilyen konfiguráció.
             */
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    private function scheduleRetry(RetryPolicy $retry): void
    {
        $message = Message::query()->where('uuid', $this->messageUuid)->first();

        if ($message === null) {
            return;
        }

        $attempt = (int) $message->attempts;

        $message->forceFill([
            'next_attempt_at' => $retry->shouldGiveUp($attempt) ? null : now()->addSeconds($retry->delaySeconds($attempt)),
            'status' => $retry->shouldGiveUp($attempt) ? 'failed' : 'deferred',
        ])->save();
    }
}
