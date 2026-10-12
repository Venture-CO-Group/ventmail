<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\SendingMode;

/**
 * Az éles küldési mód kapuja: seed-allowlist és a marketing főkapcsolója.
 *
 * Közvetlenül a bemenet-épség után fut, a többi kapu ELŐTT: amíg a platform seed-módban
 * van, az allowlisten kívüli kérés ne fogyasszon se rate-limit keretet, se kvótát.
 */
final class SendingModeGate implements Gate
{
    public function __construct(private readonly SendingMode $mode) {}

    public function name(): string
    {
        return 'sending_mode';
    }

    public function check(Message $message): GateResult
    {
        return match ($this->mode->restriction($message)) {
            null => GateResult::pass('küldési mód: engedélyezett'),
            GateReason::ChannelDisabled => GateResult::reject(
                GateReason::ChannelDisabled,
                'A marketing-küldés a platformon ki van kapcsolva.'
            ),
            default => GateResult::reject(
                GateReason::SendingRestricted,
                'Seed-mód: csak az allowlistes címzett kaphat levelet.'
            ),
        };
    }
}
