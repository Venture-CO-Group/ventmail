<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Testing;

use App\Modules\VentSec\Contracts\VentSecEvents;

/** Rögzíti a kibocsátást; nem küld semmit. */
class FakeVentSecEvents implements VentSecEvents
{
    /** @var list<array{action: string, outcome: string, context: array<string, mixed>}> */
    public array $emitted = [];

    public int $heartbeats = 0;

    public function emit(string $action, string $outcome, array $context = []): void
    {
        $this->emitted[] = ['action' => $action, 'outcome' => $outcome, 'context' => $context];
    }

    public function heartbeat(): bool
    {
        $this->heartbeats++;

        return true;
    }
}
