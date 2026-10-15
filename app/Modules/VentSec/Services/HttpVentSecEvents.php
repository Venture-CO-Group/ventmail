<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Services;

use App\Modules\VentSec\Contracts\VentSecEvents;
use App\Modules\VentSec\Jobs\SendVentSecEvent;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A VentSecEvents HTTP-megvalósítása. Kikapcsolva (`VENTSEC_EVENTS_ENABLED=false`, az alap)
 * semmit nem küld. Bekapcsolva az esemény egy `default` sorú jobbal (a Horizon `background` supervisor fogyasztja) megy ki (retry +
 * backoff), így a hívó folyamata nem várja a ventSec-et.
 *
 * TODO ventSec: tartós outbox (a `venture-security-events` SDK-é) — addig az újrapróbálás a
 * queue-é, és a tartósan sikertelen eseményt a `failed_jobs` őrzi (tartalma nem PII).
 */
final class HttpVentSecEvents implements VentSecEvents
{
    public function __construct(
        private readonly EventFactory $factory,
        private readonly VentSecIngestClient $client,
    ) {}

    public function emit(string $action, string $outcome, array $context = []): void
    {
        // Az érvényesítés kikapcsolva is lefut: a programozói hiba a tesztekben látszik.
        $event = $this->factory->make($action, $outcome, $context);

        if (! $this->enabled()) {
            return;
        }

        SendVentSecEvent::dispatch($event);
    }

    public function heartbeat(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            // Nincs outbox: a mélység és a kor 0 (TODO ventSec outbox, lásd fent).
            return $this->client->send([$this->factory->make('ventsec.heartbeat', 'success', [
                'outbox_depth' => 0,
                'outbox_oldest_seconds' => 0,
                'sdk_version' => 'ventmail-'.(string) config('app.version', 'dev'),
            ])]);
        } catch (RuntimeException $e) {
            Log::warning('ventsec.heartbeat.failed', ['reason' => $e->getMessage()]);

            return false;
        }
    }

    private function enabled(): bool
    {
        return (bool) config('ventsec.events.enabled');
    }
}
