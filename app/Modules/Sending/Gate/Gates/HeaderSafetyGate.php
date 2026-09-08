<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;
use InvalidArgumentException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;

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
     * A `List-Unsubscribe` és a `List-Unsubscribe-Post` (RFC 8058) szintén: ezeket a KÜLDÉSI
     * ÚT teszi be marketing levélhez, és az `UnsubscribeHeaderGate` meg is követeli. Ha a
     * fejléc-kapu tiltaná őket, a saját, kötelező fejlécünk akadna fenn rajta.
     *
     * A vezérlőkarakter-ellenőrzés MINDEGYIKRE lefut, tehát a fejléc-injekció ugyanúgy zárva
     * van — és a `From` továbbra is tiltott, tehát a feladó-hamisítás útja nem nyílik meg.
     *
     * @var list<string>
     */
    public const ALLOWED = ['reply-to', 'list-unsubscribe', 'list-unsubscribe-post'];

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

            // A `Reply-To` STRUKTURÁLT fejléc: a MIME-építés a szintaktikailag rossz címet
            // kénytelen kihagyni. Ha a kapu nem szólna, az ügyfél SOHA nem tudná meg, hogy a
            // válasz-címe nem ment ki — csendes funkcióvesztés, ugyanaz az osztály, amit
            // ebben a packetben kétszer javítottunk. Itt SZÓLUNK, ott hallgatunk.
            if ($normalized === 'reply-to' && ! $this->isMailbox((string) $value)) {
                return GateResult::reject(
                    GateReason::InvalidRequest,
                    'A `Reply-To` fejléc nem értelmezhető címként.',
                );
            }
        }

        // IDEGEN ventMail-leiratkozó token az ügyfél fejlécében: elutasítás.
        //
        // Ügyféltől megfejthető ventMail-token SOHA nem jöhet — ha jön, az vagy elkapott
        // (proxy-napló, továbbított levél), vagy egy MÁS TENANTÉ. Mérve: egy idegen workspace
        // tokenjét a `h:list-unsubscribe` fejlécbe illesztve a kimenő levelek MINDEN példánya
        // azt hordozta, a mi DKIM-aláírásunkkal — tehát minden one-click egy idegen tenant
        // suppression-listájára írt volna (golden rule 6).
        //
        // A SAJÁT, ehhez az üzenethez tartozó tokenünk átmegy: azt a `Message::headersFor()`
        // írja újra címzettenként.
        if ($message->carriesForeignUnsubscribeToken()) {
            return GateResult::reject(
                GateReason::InvalidRequest,
                'A `List-Unsubscribe` fejléc idegen leiratkozó tokent tartalmaz.',
            );
        }

        if ($this->hasControlCharacters((string) $message->subject)
            || $this->hasControlCharacters((string) $message->from_name)) {
            return GateResult::reject(GateReason::InvalidRequest, 'Vezérlőkarakter a tárgyban vagy a feladó nevében.');
        }

        return GateResult::pass();
    }

    /**
     * Értelmezhető cím-LISTA-e? A `Reply-To` az RFC 5322 szerint `address-list`.
     *
     * Az `Address::create()` egyetlen címet ért, tehát az `a@pelda.hu, b@pelda.hu` és a
     * nevesített lista is elutasításra futott — a Mailgun átengedi, vagyis legitim forgalmat
     * 422-eztünk volna. A `createArray()` a listát is érti, és üres listát nem fogad el.
     */
    private function isMailbox(string $value): bool
    {
        try {
            return Address::createArray(array_map('trim', explode(',', trim($value)))) !== [];
        } catch (RfcComplianceException|InvalidArgumentException) {
            return false;
        }
    }

    private function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\r\n\x00]/', $value) === 1;
    }
}
