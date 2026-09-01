<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;

/**
 * 3. kapu — marketing CSAK megerősített (double opt-in) consenttel (golden rule 4).
 *
 * A `transactional` csatornán nincs consent-követelmény: a szolgáltatás működéséhez tartozó
 * levél nem hírlevél.
 */
final class ConsentGate implements Gate
{
    public function __construct(private readonly ConsentChecker $consent) {}

    public function name(): string
    {
        return 'consent';
    }

    public function check(Message $message): GateResult
    {
        if (! $message->isMarketing()) {
            return GateResult::pass('nem marketing csatorna');
        }

        foreach ($message->recipients as $recipient) {
            if (! $this->consent->hasConfirmedConsent((string) $recipient, $message->channel)) {
                return GateResult::reject(
                    GateReason::NoConsent,
                    "Nincs megerősített marketing-consent: {$recipient}"
                );
            }
        }

        return GateResult::pass();
    }
}
