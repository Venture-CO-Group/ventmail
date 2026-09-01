<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Contracts\AddressRiskScorer;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;

/**
 * 6. kapu — címellenőrzési kockázat a küszöb alatt (spec 6.2, opcionális/kapcsolható).
 *
 * A valódi ellenőrző lánc az A5 packet gazdája; itt csak a küszöb-döntés él. A kapu
 * kikapcsolható (`sending.validation.enabled`), mert egy szigorú küszöb legitim forgalmat
 * is levágna — a döntés a tenanté.
 */
final class ValidationRiskGate implements Gate
{
    public function __construct(private readonly AddressRiskScorer $scorer) {}

    public function name(): string
    {
        return 'validation_risk';
    }

    public function check(Message $message): GateResult
    {
        if (! config('sending.validation.enabled')) {
            return GateResult::pass('kikapcsolva');
        }

        $threshold = (float) config('sending.validation.risk_threshold');

        foreach ($message->recipients as $recipient) {
            $risk = $this->scorer->riskFor((string) $recipient);

            if ($risk >= $threshold) {
                return GateResult::reject(
                    GateReason::ValidationRisk,
                    sprintf('%s kockázata %.2f (küszöb: %.2f)', $recipient, $risk, $threshold)
                );
            }
        }

        return GateResult::pass();
    }
}
