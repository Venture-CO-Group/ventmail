<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

/**
 * STRIPPED-TEXT: az idézett előzmény és az aláírás levágása (34. kritérium).
 *
 * Ez az a mező, amitől a válasz-kezelés (ticketing, reply-to-comment) egyáltalán működik. Ha
 * a ventSupport a teljes törzset kapná, minden válasz alá bemásolódna az egész
 * levélváltás — a jegy olvashatatlanná válna három kör után.
 *
 * A minták MAGYARUL ÉS ANGOLUL is kellenek, mert a levélkliensek a felhasználó nyelvén írják
 * az idézőblokk fejlécét, és a magyar ügyfélkör magyar Outlookot és Gmailt használ.
 *
 * ÓVATOS a vágás: ha a levágás után nem maradna semmi, INKÁBB a teljes törzset adjuk vissza.
 * Egy üres `stripped-text` rosszabb, mint egy hosszú: az előbbiből a support nem tud dolgozni,
 * az utóbbiból igen.
 */
final class StrippedTextExtractor
{
    /**
     * Az IDÉZŐBLOKK kezdete. Ami ezektől lefelé van, az az előzmény.
     *
     * @var list<string>
     */
    private const QUOTE_MARKERS = [
        // Gmail / Thunderbird, magyar és angol
        '/^\s*\d{4}\.\s*\w+\.?\s*\d{1,2}\.?.{0,60}(írta|ezt írta)\s*:/iu',
        '/^\s*On\s+.{3,80}\s+wrote:\s*$/iu',
        '/^\s*\d{4}[-.\/]\d{1,2}[-.\/]\d{1,2}.{0,60}<[^>]+>\s*:?\s*$/u',
        // Outlook, magyar és angol
        '/^\s*-{2,}\s*(Eredeti üzenet|Original Message|Továbbított üzenet|Forwarded message)\s*-{2,}/iu',
        '/^\s*_{5,}\s*$/u',
        '/^\s*(Feladó|From)\s*:\s*.+$/iu',
        // Idézett sorok blokkja
        '/^\s*>{1,}\s?/u',
    ];

    /**
     * Az ALÁÍRÁS kezdete.
     *
     * @var list<string>
     */
    private const SIGNATURE_MARKERS = [
        // RFC 3676 §4.3: a `-- ` (kötőjel, kötőjel, szóköz) a szabványos aláírás-elválasztó.
        '/^--\s?$/u',
        '/^\s*(Üdvözlettel|Üdv|Tisztelettel|Baráti üdvözlettel)[,!.]?\s*$/iu',
        '/^\s*(Best regards|Kind regards|Regards|Sincerely|Cheers)[,!.]?\s*$/iu',
        '/^\s*(Sent from my|Elküldve innen:)/iu',
    ];

    public function extract(?string $plain): ?string
    {
        if ($plain === null || trim($plain) === '') {
            return null;
        }

        $lines = preg_split('/\r?\n/', $plain) ?: [];

        $cut = $this->firstMarker($lines, self::QUOTE_MARKERS);
        $signature = $this->firstMarker($lines, self::SIGNATURE_MARKERS);

        if ($signature !== null && ($cut === null || $signature < $cut)) {
            $cut = $signature;
        }

        $kept = $cut === null ? $lines : array_slice($lines, 0, $cut);

        $stripped = trim(implode("\n", $kept));

        // ÓVATOSSÁG: ha a vágás mindent elvitt, a teljes törzs a jobb válasz.
        return $stripped === '' ? trim($plain) : $stripped;
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $markers
     */
    private function firstMarker(array $lines, array $markers): ?int
    {
        foreach ($lines as $index => $line) {
            foreach ($markers as $marker) {
                if (preg_match($marker, $line) === 1) {
                    return $index;
                }
            }
        }

        return null;
    }
}
