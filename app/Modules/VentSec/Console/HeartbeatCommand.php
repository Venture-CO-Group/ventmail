<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Console;

use App\Modules\VentSec\Contracts\VentSecEvents;
use Illuminate\Console\Command;

/** `ventsec:heartbeat` — percenkénti szívverés a ventSec-nek (csak bekapcsolt kibocsátásnál). */
final class HeartbeatCommand extends Command
{
    protected $signature = 'ventsec:heartbeat';

    protected $description = 'ventsec.heartbeat esemény küldése (VENTSEC_EVENTS_ENABLED=true esetén).';

    public function handle(VentSecEvents $events): int
    {
        if (! config('ventsec.events.enabled')) {
            $this->info('A ventSec-eseménykibocsátás ki van kapcsolva — nincs teendő.');

            return self::SUCCESS;
        }

        if (! $events->heartbeat()) {
            $this->error('A szívverés nem ment ki.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
