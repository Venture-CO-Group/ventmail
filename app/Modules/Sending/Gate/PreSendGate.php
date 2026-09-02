<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sending\Gate\Gates\Gate;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
        private readonly WorkspaceContext $context,
    ) {}

    public function evaluate(Message $message): GateDecision
    {
        // Kontextus nélkül a suppression-kapu fail-OPEN lenne: a WorkspaceOrPlatformScope
        // ilyenkor csak a platform-sorokat mutatja, tehát a tenant tiltása láthatatlan.
        // Az A2 SMTP relay és a queue-jobok épp ilyen belépési pontok lesznek.
        if (! $this->context->hasWorkspace()) {
            throw new RuntimeException(
                'A PreSendGate workspace-kontextust igényel — enélkül a suppression-kapu vak lenne.'
            );
        }

        $results = [];

        foreach ($this->gates as $gate) {
            $result = $this->runGate($gate, $message);
            $results[$gate->name()] = $result;

            if (! $result->passed) {
                return $this->record(GateDecision::rejected($result->reason, $results), $message);
            }
        }

        return $this->record(GateDecision::allowed($results), $message);
    }

    /**
     * Egy kapu futtatása FAIL-CLOSED hibakezeléssel.
     *
     * Ha egy kapu kivételre fut (Redis-kiesés, ventPay-timeout, consent-DB hiba), a lánc
     * NEM engedhet át: inkább nem küldünk, mint ellenőrizetlenül. A kivétel SZÖVEGE nem
     * kerül a döntésbe (titok-szivárgás ellen) — az a szerver-logba megy.
     */
    private function runGate(Gate $gate, Message $message): GateResult
    {
        try {
            return $gate->check($message);
        } catch (Throwable $e) {
            Log::error('PreSendGate kapu-hiba', [
                'gate' => $gate->name(),
                'message_uuid' => $message->uuid,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return GateResult::reject(
                GateReason::GateError,
                sprintf('A(z) %s kapu nem tudott dönteni; a küldés biztonsági okból elmarad.', $gate->name())
            );
        }
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
