<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;

/**
 * 2. kapu — suppression (globális + workspace szintű).
 *
 * KRITIKUS RÉSZLET: a `marketing` hatókörű tiltás (leiratkozás) NEM blokkolja a
 * `transactional` forgalmat — a hírlevél-leiratkozás nem némíthatja el a jelszó-visszaállító
 * levelet (golden rule 4).
 */
final class SuppressionGate implements Gate
{
    public function name(): string
    {
        return 'suppression';
    }

    public function check(Message $message): GateResult
    {
        foreach ($message->recipients as $recipient) {
            $suppression = Suppression::query()
                ->where('email', strtolower(trim((string) $recipient)))
                ->where(fn ($query) => $query->where('applies_to', 'all')
                    ->orWhere('applies_to', $message->channel))
                ->get()
                ->first(fn (Suppression $row) => $row->isActive());

            if ($suppression !== null) {
                return GateResult::reject(
                    GateReason::Suppressed,
                    sprintf(
                        '%s tiltva (%s, hatókör: %s%s)',
                        $recipient,
                        $suppression->reason,
                        $suppression->applies_to,
                        $suppression->isPlatformWide() ? ', globális' : ''
                    )
                );
            }
        }

        return GateResult::pass();
    }
}
