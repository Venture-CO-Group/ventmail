<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Jobs;

use App\Modules\VentSec\Services\VentSecIngestClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Egy VSES esemény kiküldése a ventSec ingestnek. Az újrapróbálható hiba (hálózat, 5xx, 429,
 * token) kivétel → a queue backoff-fal újrapróbálja; a végleges elutasítás (pl. 403
 * `ingest_gated`, amíg a ventSec prod ingest zárva) csendben lezárja a jobot.
 */
final class SendVentSecEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    /** @param array<string, mixed> $event */
    public function __construct(public readonly array $event)
    {
        $this->onQueue('background');
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function handle(VentSecIngestClient $client): void
    {
        $client->send([$this->event]);
    }
}
