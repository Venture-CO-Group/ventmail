<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Enums\Channel;
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
        $channel = Channel::from((string) $message->channel);

        // A hatókör a CSATORNÁBÓL jön, nem a nyers sztringből: a journey és a campaign is
        // marketing-hatókörű, tehát a hírlevél-leiratkozás azokra is érvényes.
        $scope = $channel->suppressionScope();

        $recipients = collect($message->recipients)
            ->map(fn ($recipient) => strtolower(trim((string) $recipient)));

        // Egyetlen lekérdezés: 1000 címzettnél a címzettenkénti query 1000 körutazás lenne.
        $suppressed = Suppression::query()
            ->whereIn('email', $recipients->all())
            ->whereIn('applies_to', ['all', $scope])
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();

        if ($suppressed->isNotEmpty()) {
            $first = $suppressed->first();
            $index = $recipients->search($first->email);

            // A `detail` az audit-naplóba kerül, ami SOHA nem törölhető — nyers címzett-cím
            // nem mehet bele (GDPR Art. 17). Index + ok elég a diagnózishoz.
            return GateResult::reject(
                GateReason::Suppressed,
                sprintf(
                    'címzett #%s tiltva (%s, hatókör: %s%s)',
                    $index === false ? '?' : $index,
                    $first->reason,
                    $first->applies_to,
                    $first->isPlatformWide() ? ', globális' : ''
                )
            );
        }

        return GateResult::pass();
    }
}
