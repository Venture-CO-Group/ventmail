<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Enums\Channel;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\DomainNormalizer;

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
            if (! is_string($recipient) || ! $this->isValidAddress($recipient)) {
                return GateResult::reject(GateReason::InvalidRequest, "Érvénytelen címzett-cím: #{$index}");
            }
        }

        if (! $this->isValidAddress((string) $message->from_email)) {
            return GateResult::reject(GateReason::InvalidRequest, 'Érvénytelen feladó-cím.');
        }

        return GateResult::pass(sprintf('%d címzett', count($recipients)));
    }

    /**
     * Cím-ellenőrzés NEMZETKÖZI címekre is (RFC 6531, `SMTPUTF8`).
     *
     * A `filter_var(FILTER_VALIDATE_EMAIL)` ASCII-t vár, tehát az `info@példa.hu` és az
     * `Ünnepi@pelda.hu` mind érvénytelennek látszana — pedig a tulajdonosi döntés szerint
     * az induló ügyfélkörnek KELL az IDN. Ezért:
     *  - a DOMAINT punycode alakra hozzuk, és ÚGY validáljuk (a nemzetközi domain ASCII
     *    megfelelője ugyanaz a domain);
     *  - a HELYI RÉSZT nem alakítjuk át (RFC 5321 §2.4: csak a fogadó értelmezheti), de
     *    kizárjuk azt, ami a protokollt vagy a fejléceket törné: vezérlőkarakter,
     *    whitespace, idézőjel, `<`, `>`, `,`, `;`, `:` és a többszörös `@`.
     */
    private function isValidAddress(string $address): bool
    {
        $position = strrpos($address, '@');

        if ($position === false || $position === 0 || $position === strlen($address) - 1) {
            return false;
        }

        $local = substr($address, 0, $position);
        $domain = DomainNormalizer::canonical(substr($address, $position + 1));

        if (strlen($local) > 64 || preg_match('/[\x00-\x20\x7f<>,;:"\\@]/', $local) === 1) {
            return false;
        }

        // A domaint ASCII alakban a beépített validátor nézi meg (pontok, hossz, TLD).
        return filter_var('validation-probe@'.$domain, FILTER_VALIDATE_EMAIL) !== false;
    }
}
