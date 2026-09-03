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
        // A magányos `\r` sortörésnek SZÁMÍT. Enélkül az `Organization: a\rFrom: ceo@bank.hu`
        // egyetlen fejlécértéknek látszott, tehát a duplikátum-ellenőrzés nem futott rá —
        // ha viszont bármely hop CRLF-re kanonizálja, két `From` lesz a levélben, és a
        // levélkliens az elsőt mutatja. A vizsgálat a NORMALIZÁLT másolaton megy; a
        // kiküldött bájtok változatlanok.
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        // A határ az ELSŐ üres sor — sorvég-fajtától függetlenül. A korábbi
        // „van-e valahol CRLFCRLF" kérdés GLOBÁLIS volt: ha a fejlécblokk `\n`-nel zárult,
        // de a TÖRZS bárhol tartalmazott CRLFCRLF-et (idézett levél, base64-blokk), a határ
        // a törzsbe csúszott, és a törzs sorai fejlécként parse-olódtak — a törzsbe írt
        // `To:` sor így valódi címzetté vált.
        $position = preg_match('/\r?\n\r?\n/', $raw, $matches, PREG_OFFSET_CAPTURE) === 1
            ? $matches[0][1]
            : false;

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

        // A `From` PONTOSAN EGY postafiókot tartalmazhat (RFC 5322 §3.6.2 — több feladó
        // esetén `Sender` is kötelező lenne). Enélkül a mohó `<...>` illesztés miatt a
        // `From: <ceo@bank.example> <hello@sajat.hu>` alakból nálunk a MÁSODIK cím lett a
        // feladó (tehát a domain-kapu és a hitelesítő domain-kötése is átment), a fogadók
        // egy része viszont az ELSŐT jeleníti meg — feladó-hamisítás a saját DKIM-ünkkel.
        if ($this->hasMultipleMailboxes($from)) {
            throw new InvalidArgumentException(
                'MIME_MALFORMED|A `From` fejléc pontosan egy feladót tartalmazhat.'
            );
        }

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
            // Üres sor = a fejlécblokk vége. Nem `continue`: az átugrás miatt egy rosszul
            // vágott blokk törzs-sorai is fejlécként folytatódtak volna.
            if (trim($line, "\r") === '') {
                break;
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

    /**
     * Több feladót rejt-e a fejléc?
     *
     * A számolás CSAK az idézőjeleken (és a kódolt szavakon) kívüli részre vonatkozik: a
     * `"support@pelda.hu" <support@pelda.hu>` a világ legelterjedtebb display-name
     * konvenciója (a megjelenítendő név maga a cím), és a naiv `@`-számolás ezt is
     * elutasította volna — minden ilyen ügyfélnek 550-et adva a nyers MIME API-n ÉS az
     * SMTP relayen.
     */
    private function hasMultipleMailboxes(string $from): bool
    {
        // Idézőjeles részek és zárójeles kommentek eltávolítása (RFC 5322 §3.2.2/§3.2.4) —
        // a kódolt szó (`=?utf-8?Q?...?=`) is legálisan tartalmazhat `@`-ot.
        $bare = (string) preg_replace('/"(?:\\\\.|[^"\\\\])*"/', '', $from);
        $bare = (string) preg_replace('/\((?:[^()]|\([^()]*\))*\)/', '', $bare);
        $bare = (string) preg_replace('/=\?[^?]*\?[BbQq]\?[^?]*\?=/', '', $bare);

        return substr_count($bare, '<') > 1 || substr_count($bare, '@') > 1;
    }

    /** @return array{0: string, 1: ?string} */
    private function parseAddress(string $value): array
    {
        // A zárójeles komment LEGÁLIS alak (`hello@pelda.hu (Pelda Kft)`), de nem része a
        // címnek — bent hagyva a „cím" `hello@pelda.hu (pelda kft)` lett volna.
        $value = trim((string) preg_replace('/\((?:[^()]|\([^()]*\))*\)/', '', $value));

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
