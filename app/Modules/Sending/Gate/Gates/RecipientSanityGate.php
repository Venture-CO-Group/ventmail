<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Enums\Channel;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;

/**
 * 0. kapu — bemenet-épség. NEM kapcsolható ki.
 *
 * Miért kell külön kapu: a ciklusos kapuk (suppression, consent, kockázat) ÜRES címzett-
 * listán egyszerűen nem futottak le, és „passed"-del tértek vissza — üres `to` + `Bcc`
 * fejléc kombinációval az egész lánc megkerülhető volt. A szintaxis-ellenőrzés pedig
 * korábban csak a KIKAPCSOLHATÓ 6. kapuban élt.
 *
 * Itt dől el az is, hogy a csatorna ismert-e: ismeretlen csatorna elutasítás, nem
 * „nem marketing".
 */
final class RecipientSanityGate implements Gate
{
    public function name(): string
    {
        return 'recipient_sanity';
    }

    public function check(Message $message): GateResult
    {
        if (Channel::tryFrom((string) $message->channel) === null) {
            return GateResult::reject(
                GateReason::InvalidRequest,
                'Ismeretlen csatorna; engedélyezett: '.implode(', ', Channel::values())
            );
        }

        $recipients = $message->recipients;

        if ($recipients === []) {
            return GateResult::reject(GateReason::InvalidRequest, 'Nincs címzett.');
        }

        $max = (int) config('sending.max_recipients');

        if (count($recipients) > $max) {
            return GateResult::reject(
                GateReason::InvalidRequest,
                sprintf('Túl sok címzett: %d (max %d)', count($recipients), $max)
            );
        }

        foreach ($recipients as $index => $recipient) {
            if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                return GateResult::reject(GateReason::InvalidRequest, "Érvénytelen címzett-cím: #{$index}");
            }
        }

        if (! filter_var((string) $message->from_email, FILTER_VALIDATE_EMAIL)) {
            return GateResult::reject(GateReason::InvalidRequest, 'Érvénytelen feladó-cím.');
        }

        return GateResult::pass(sprintf('%d címzett', count($recipients)));
    }
}
