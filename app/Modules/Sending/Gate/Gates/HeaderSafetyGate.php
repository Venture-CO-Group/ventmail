<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;

/**
 * 0/b. kapu — fejléc-biztonság.
 *
 * A `h:X-*` fejlécek a Mailgun-paritás részei, de szűretlenül eljutottak az MTA-hoz. Ezzel
 * (a) a `From` felülírható volt, tehát a domain-kapu döntése értelmét vesztette, és
 * (b) egy `Bcc` OLYAN CÍMZETTET adott hozzá, akit egyetlen kapu sem látott.
 * A CRLF-szűrés hiánya pedig az A2 SMTP relay élesedésekor klasszikus fejléc-injekciót
 * nyitna.
 */
final class HeaderSafetyGate implements Gate
{
    /**
     * Címzettet vagy feladót vezérlő fejléc a hívótól SOHA nem jöhet.
     *
     * A `reply-to` SZÁNDÉKOSAN NINCS itt (lásd `ALLOWED`): az nem a borítékot és nem a
     * feladót vezérli, hanem azt mondja meg az olvasónak, hova menjen a válasz.
     */
    private const FORBIDDEN = [
        'to', 'cc', 'bcc', 'from', 'sender', 'return-path', 'received',
        'message-id', 'date', 'mime-version', 'content-type', 'content-transfer-encoding',
    ];

    /**
     * Az `X-` prefixen KÍVÜL engedélyezett fejlécek.
     *
     * A `Reply-To` nem vezérel sem borítékot, sem feladót: kizárólag azt mondja meg az
     * OLVASÓNAK, hova menjen a válasz. Enélkül a bejövő továbbítás (A4 · 32. kritérium)
     * használhatatlan lenne — a továbbított levélre a válasz a `noreply@` címünkre menne,
     * nem az eredeti feladónak. Mailgun-paritás is (`h:Reply-To`).
     *
     * A vezérlőkarakter-ellenőrzés ERRE IS lefut, tehát a fejléc-injekció ugyanúgy zárva van —
     * és a `From` továbbra is tiltott, tehát a feladó-hamisítás útja nem nyílik meg.
     *
     * @var list<string>
     */
    private const ALLOWED = ['reply-to'];

    public function name(): string
    {
        return 'header_safety';
    }

    public function check(Message $message): GateResult
    {
        foreach ((array) $message->headers as $name => $value) {
            $normalized = strtolower(trim((string) $name));

            if (in_array($normalized, self::FORBIDDEN, true)) {
                return GateResult::reject(
                    GateReason::InvalidRequest,
                    "Tiltott fejléc: {$normalized} (a címzett- és feladó-vezérlés a gate-é)"
                );
            }

            if (! str_starts_with($normalized, 'x-') && ! in_array($normalized, self::ALLOWED, true)) {
                return GateResult::reject(
                    GateReason::InvalidRequest,
                    "Csak X- fejléc adható meg, ez nem az: {$normalized}"
                );
            }

            if ($this->hasControlCharacters((string) $name) || $this->hasControlCharacters((string) $value)) {
                return GateResult::reject(GateReason::InvalidRequest, 'Fejléc-injekció (CRLF) kísérlet.');
            }
        }

        if ($this->hasControlCharacters((string) $message->subject)
            || $this->hasControlCharacters((string) $message->from_name)) {
            return GateResult::reject(GateReason::InvalidRequest, 'Vezérlőkarakter a tárgyban vagy a feladó nevében.');
        }

        return GateResult::pass();
    }

    private function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\r\n\x00]/', $value) === 1;
    }
}
