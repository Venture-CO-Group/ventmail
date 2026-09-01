<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sending\Gate\Gates\Gate;
use App\Modules\Sending\Models\Message;

/**
 * A hat kapu (spec 6.2, BLOCKING).
 *
 * MEGKERÜLHETETLEN: a REST-en (A1) és az SMTP relayen (A2) beadott üzenet is ezen megy át.
 * A kapuk sorrendben futnak, az első elutasítás megállítja a láncot — de a döntés rögzíti,
 * meddig jutott, hogy az üzenet-trace (A3) a teljes képet mutathassa.
 *
 * MINDEN döntés auditált, az elfogadás is: enélkül a „miért ment ki?" kérdésre nem lenne
 * válasz, csak a „miért nem".
 */
final class PreSendGate
{
    /** @param iterable<Gate> $gates */
    public function __construct(
        private readonly iterable $gates,
        private readonly AuditLogger $audit,
    ) {}

    public function evaluate(Message $message): GateDecision
    {
        $results = [];

        foreach ($this->gates as $gate) {
            $result = $gate->check($message);
            $results[$gate->name()] = $result;

            if (! $result->passed) {
                return $this->record(GateDecision::rejected($result->reason, $results), $message);
            }
        }

        return $this->record(GateDecision::allowed($results), $message);
    }

    private function record(GateDecision $decision, Message $message): GateDecision
    {
        $this->audit->log(
            $decision->allowed ? 'sending.gate.allowed' : 'sending.gate.rejected',
            $message,
            after: [
                'reason' => $decision->reason,
                'channel' => $message->channel,
                'gates' => $decision->trace(),
            ],
        );

        return $decision;
    }
}
