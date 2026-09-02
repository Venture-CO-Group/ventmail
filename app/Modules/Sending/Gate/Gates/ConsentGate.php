<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Sending\Enums\Channel;
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
        // MEGFORDÍTOTT logika: nem azt kérdezzük, „marketing-e", hanem hogy
        // consent-mentes-e. Egy ismeretlen csatorna így NEM csúszik át.
        $channel = Channel::from((string) $message->channel);

        if (! $channel->requiresConsent()) {
            return GateResult::pass('tranzakciós csatorna');
        }

        // Egyetlen lekérdezés: 1000 címzettnél a címzettenkénti ellenőrzés 1000 körutazás
        // lenne, szinkron a kérésben.
        $missing = $this->consent->withoutConsent(
            array_map(static fn ($recipient) => (string) $recipient, $message->recipients),
            $channel->value
        );

        if ($missing !== []) {
            // Nyers cím SOHA nem kerül a törölhetetlen auditba — darabszám elég.
            return GateResult::reject(
                GateReason::NoConsent,
                sprintf('%d címzettnek nincs megerősített consentje (%s)', count($missing), $channel->value)
            );
        }

        return GateResult::pass();
    }
}
