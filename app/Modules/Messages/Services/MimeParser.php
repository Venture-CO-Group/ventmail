<?php

declare(strict_types=1);

namespace App\Modules\Messages\Services;

use InvalidArgumentException;

/**
 * Nyers MIME kimenő levél értelmezése (spec 8.1).
 *
 * Miért kell: aki saját levelet állít össze (Rails ActionMailer, MIME-könyvtárak), az a
 * kész MIME-t küldi be. Ha ezt nem támogatnánk, az integrációt át kellene írni — az M1
 * globális DoD-ja pedig épp azt kéri, hogy ne kelljen.
 *
 * FIGYELEM: ez a KIMENŐ út parsere, és csak a FEJLÉCEKET olvassa (feladó, címzett, tárgy),
 * hogy a PreSendGate dönteni tudjon. A törzs változatlanul megy tovább. A BEJÖVŐ oldal
 * parsere (A4) ettől külön él, sandboxban — ott a tartalom ismeretlen eredetű.
 */
final class MimeParser
{
    /** A fejléc-blokk felső korlátja: egy 10 MB-os fejléc-tömb DoS lenne. */
    private const MAX_HEADER_BYTES = 65536;

    /**
     * RFC 5322 §3.6: ezek a fejlécek legfeljebb EGYSZER szerepelhetnek.
     *
     * Miért kritikus: duplikáció esetén a levélkliens az ELSŐT mutatja, a parser viszont
     * (bármelyik irányban) csak az egyiket látja. Ha a gate a másikat vizsgálja, idegen
     * `From` jelenhet meg a SAJÁT DKIM-ünkkel aláírva. Ezért itt nem „valamelyik győz",
     * hanem ELUTASÍTÁS.
     */
    private const SINGLETON_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'sender', 'reply-to', 'subject', 'date', 'message-id',
    ];

    /**
     * @return array{from_email: string, from_name: ?string, recipients: list<string>, subject: ?string, headers: array<string, string>}
     */
    public function parseHeaders(string $raw): array
    {
        $separator = str_contains($raw, "\r\n\r\n") ? "\r\n\r\n" : "\n\n";
        $position = strpos($raw, $separator);

        // `=== false` ÉS `=== 0` külön: a `?:` mindkettőt falsy-nak vette, és ilyenkor a
        // TELJES törzs fejlécként parse-olódott — a törzsben idézett `To:` sorok
        // boríték-címzettekké léptek elő.
        if ($position === false) {
            throw new InvalidArgumentException('MIME_MALFORMED|A MIME-levélben nincs fejléc/törzs elválasztó.');
        }

        if ($position === 0) {
            throw new InvalidArgumentException('MIME_MALFORMED|A MIME-levél fejléc nélkül kezdődik.');
        }

        // A limitet a vágás ELŐTT érvényesítjük — különben a `substr` mindig 64 KB-ra vág,
        // és az ellenőrzés soha nem üt be.
        if ($position > self::MAX_HEADER_BYTES) {
            throw new InvalidArgumentException('MIME_HEADERS_TOO_LARGE|A MIME fejléc-blokk túl nagy.');
        }

        $headers = $this->unfold(substr($raw, 0, $position));

        $from = $headers['from'] ?? throw new InvalidArgumentException(
            'MIME_MISSING_FROM|A MIME-levélből hiányzik a `From:` fejléc.'
        );

        [$fromEmail, $fromName] = $this->parseAddress($from);

        // A `Bcc` a MIME-ban rejtett címzett: ha csendben eldobnánk, a feladó azt hinné,
        // hogy kiment — ha pedig továbbadnánk, egyetlen kapu sem látta volna. Explicit hiba.
        if (isset($headers['bcc'])) {
            throw new InvalidArgumentException(
                'MIME_BCC_NOT_SUPPORTED|A `Bcc` fejléc nem támogatott: add meg a rejtett '
                .'címzetteket a `to` mezőben, hogy a kapukon átmehessenek.'
            );
        }

        $recipients = [];

        foreach (['to', 'cc'] as $field) {
            if (isset($headers[$field])) {
                foreach (explode(',', $headers[$field]) as $address) {
                    [$email] = $this->parseAddress($address);

                    if ($email !== '') {
                        $recipients[] = $email;
                    }
                }
            }
        }

        // A saját `X-` fejléceket MEGŐRIZZÜK (a feladó szándéka), a vezérlőket nem: azokat
        // a gate és a driver állítja elő.
        $preserved = [];

        foreach ($headers as $name => $value) {
            if (str_starts_with($name, 'x-')) {
                $preserved[$this->canonicalName($name)] = $value;
            }
        }

        return [
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'recipients' => array_values(array_unique($recipients)),
            'subject' => $headers['subject'] ?? null,
            'headers' => $preserved,
        ];
    }

    /**
     * A folytatósorok („folding", RFC 5322 2.2.3) összefűzése.
     *
     * @return array<string, string>
     */
    private function unfold(string $block): array
    {
        $lines = preg_split('/\r?\n/', $block) ?: [];
        $headers = [];
        $current = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            // A folytatósor szóközzel vagy tabbal kezdődik.
            if ($current !== null && preg_match('/^[ \t]/', $line) === 1) {
                $headers[$current] .= ' '.trim($line);

                continue;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $current = strtolower(trim($name));

            if (isset($headers[$current]) && in_array($current, self::SINGLETON_HEADERS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'MIME_DUPLICATE_HEADER|A(z) `%s` fejléc többször szerepel. A levélkliens az '
                    .'ELSŐT mutatja, az ellenőrzés a másikat látná — ez feladó-hamisítás lenne.',
                    $current
                ));
            }

            $headers[$current] = trim($value);
        }

        return $headers;
    }

    /** @return array{0: string, 1: ?string} */
    private function parseAddress(string $value): array
    {
        $value = trim($value);

        if (preg_match('/^(.*)<\s*([^>]+)\s*>$/', $value, $matches) === 1) {
            $name = trim($matches[1], " \t\"'");

            return [strtolower(trim($matches[2])), $name !== '' ? $name : null];
        }

        return [strtolower($value), null];
    }

    private function canonicalName(string $lowercase): string
    {
        return implode('-', array_map('ucfirst', explode('-', $lowercase)));
    }
}
