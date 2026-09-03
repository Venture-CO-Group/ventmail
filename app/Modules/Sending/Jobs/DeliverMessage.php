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
            // Hosszan élő worker: a bent felejtett kontextus a KÖVETKEZŐ job levelét
            // idegen tenantnak könyvelné el.
            $context->forget();
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
