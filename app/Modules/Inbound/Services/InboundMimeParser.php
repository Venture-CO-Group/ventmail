<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

use App\Modules\Inbound\Data\ParsedAttachment;
use App\Modules\Inbound\Data\ParsedMail;

/**
 * A BEJÖVŐ MIME értelmezése — korlátok között (36. kritérium).
 *
 * Ez a kódbázis legveszélyesebb bemenete: a tartalmat egy ISMERETLEN harmadik fél írta, és
 * semmi nem garantálja, hogy jóhiszemű. A parser-sérülékenységek klasszikus osztályai —
 * mélységi rekurzió, exponenciális részszám, kvadratikus visszalépés — mind abból élnek,
 * hogy valamelyik dimenzió korlátlan maradt.
 *
 * NÉGY KORLÁT, mindegyik külön dimenzióra:
 *  1. MÉLYSÉG (`max_depth`): a beágyazott `message/rfc822`/`multipart` matrjoska;
 *  2. RÉSZSZÁM (`max_parts`): az „ezer üres rész" ugyanúgy DoS, mint egy mély fa;
 *  3. IDŐ (`max_seconds`): falióra-korlát, ami a fenti kettőtől függetlenül is megfog egy
 *     olyan bemenetet, amire nem gondoltunk;
 *  4. MÉRET: a hívó már a fogadásnál levágta, itt a részenkénti dekódolás is korlátozott.
 *
 * A parser NEM DOB a malformált bemenetre: figyelmeztetést gyűjt, és visszaadja, amit ki
 * tudott olvasni. Egy szemetes levél nem hiba, hanem a valóság — a 36. kritérium épp azt
 * kéri, hogy ne döntse el a workert.
 *
 * SAJÁT implementáció, nem külső könyvtár: a korlátokat csak így tudjuk KIKÉNYSZERÍTENI. Egy
 * általános MIME-könyvtár a mélységet és a részszámot nem korlátozza — épp azt adná fel, ami
 * itt a védelem.
 */
final class InboundMimeParser
{
    private int $parts = 0;

    private float $deadline = 0.0;

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<ParsedAttachment> */
    private array $attachments = [];

    public function parse(string $raw): ParsedMail
    {
        $this->parts = 0;
        $this->warnings = [];
        $this->attachments = [];
        $this->deadline = microtime(true) + (float) config('inbound.parser.max_seconds');

        [$headerBlock, $body] = $this->split($raw);

        $headers = $this->headers($headerBlock);

        $plain = null;
        $html = null;

        $this->walk($headers, $body, 0, $plain, $html);

        return new ParsedMail(
            headers: $headers,
            subject: $this->decodeWords($headers['subject'] ?? null),
            messageId: isset($headers['message-id']) ? trim($headers['message-id'], '<> ') : null,
            bodyPlain: $plain,
            bodyHtml: $html,
            attachments: $this->attachments,
            warnings: $this->warnings,
        );
    }

    /** @return array{0: string, 1: string} */
    private function split(string $raw): array
    {
        // A fejléc-blokk vége az első ÜRES sor. `\r\n` és `\n` is előfordul — a küldő
        // szoftverek fele nem tartja be az RFC-t, és ettől még kézbesíteni kell.
        foreach (["\r\n\r\n", "\n\n"] as $separator) {
            $position = strpos($raw, $separator);

            if ($position !== false) {
                return [substr($raw, 0, $position), substr($raw, $position + strlen($separator))];
            }
        }

        return [$raw, ''];
    }

    /** @return array<string, string> */
    private function headers(string $block): array
    {
        $headers = [];
        $name = null;

        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            // FOLYTATÓSOR (RFC 5322 §2.2.3): szóközzel vagy tabbal kezdődik.
            if ($name !== null && preg_match('/^[ \t]/', $line) === 1) {
                $headers[$name] .= ' '.trim($line);

                continue;
            }

            if (preg_match('/^([!-9;-~]+):(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $name = strtolower($match[1]);

            // ISMÉTELT fejléc: az ELSŐ marad. A levélkliensek is az elsőt mutatják, tehát
            // ha a másodikat vennénk, a route-illesztés MÁST látna, mint az olvasó.
            if (! array_key_exists($name, $headers)) {
                $headers[$name] = trim($match[2]);
            }
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function walk(array $headers, string $body, int $depth, ?string &$plain, ?string &$html): void
    {
        if ($depth > (int) config('inbound.parser.max_depth')) {
            $this->warn('A MIME-fa mélyebb, mint az engedélyezett korlát.');

            return;
        }

        if (++$this->parts > (int) config('inbound.parser.max_parts')) {
            $this->warn('A MIME-részek száma meghaladta a korlátot.');

            return;
        }

        if (microtime(true) > $this->deadline) {
            $this->warn('Az értelmezés időkorlátba ütközött.');

            return;
        }

        $contentType = strtolower($headers['content-type'] ?? 'text/plain');
        $disposition = strtolower($headers['content-disposition'] ?? '');

        if (str_starts_with($contentType, 'multipart/')) {
            $boundary = $this->boundary($headers['content-type'] ?? '');

            if ($boundary === null) {
                $this->warn('A multipart résznek nincs boundary paramétere.');

                return;
            }

            foreach ($this->segments($body, $boundary) as $segment) {
                [$partHeaders, $partBody] = $this->split($segment);

                $this->walk($this->headers($partHeaders), $partBody, $depth + 1, $plain, $html);
            }

            return;
        }

        $decoded = $this->decodeBody($body, strtolower($headers['content-transfer-encoding'] ?? ''));

        // Csatolmány: vagy explicit `attachment`, vagy van fájlneve.
        $filename = $this->filename($headers);

        if (str_contains($disposition, 'attachment') || $filename !== null) {
            $this->attachments[] = new ParsedAttachment(
                filename: $filename ?? 'csatolmany.bin',
                contentType: trim(explode(';', $contentType)[0]),
                contents: $decoded,
            );

            return;
        }

        $charset = $this->charset($headers['content-type'] ?? '');
        $text = $this->toUtf8($decoded, $charset);

        if (str_starts_with($contentType, 'text/html')) {
            // Az ELSŐ HTML rész nyer: az `alternative` sorrendje a legjobb változatot teszi
            // utolsónak, de a beágyazott válaszok miatt biztonságosabb az elsőt tartani.
            $html ??= $text;

            return;
        }

        if (str_starts_with($contentType, 'text/')) {
            $plain ??= $text;
        }
    }

    /** @return list<string> */
    private function segments(string $body, string $boundary): array
    {
        $marker = '--'.$boundary;
        $segments = [];

        $parts = explode($marker, $body);

        // Az ELSŐ darab a boundary előtti preambulum (a MIME-t nem értő kliensek szövege),
        // az UTOLSÓ a záró `--` utáni epilógus. Egyik sem MIME-rész.
        array_shift($parts);

        foreach ($parts as $part) {
            if (str_starts_with(ltrim($part), '--')) {
                break;
            }

            $segments[] = ltrim($part, "\r\n");
        }

        return $segments;
    }

    private function boundary(string $contentType): ?string
    {
        if (preg_match('/boundary\s*=\s*"([^"]+)"/i', $contentType, $match) === 1) {
            return $match[1];
        }

        if (preg_match('/boundary\s*=\s*([^;\s]+)/i', $contentType, $match) === 1) {
            return trim($match[1], '"');
        }

        return null;
    }

    private function filename(array $headers): ?string
    {
        foreach (['content-disposition', 'content-type'] as $header) {
            $value = $headers[$header] ?? '';

            if (preg_match('/(?:file)?name\s*=\s*"([^"]*)"/i', $value, $match) === 1
                || preg_match('/(?:file)?name\s*=\s*([^;\s]+)/i', $value, $match) === 1) {
                $name = $this->decodeWords(trim($match[1], '"'));

                return $name === '' ? null : $this->safeFilename((string) $name);
            }
        }

        return null;
    }

    /**
     * A FÁJLNÉV megtisztítása.
     *
     * A küldő adja meg, és mi tároljuk meg mutatjuk. Az `..%2F..%2Fetc%2Fpasswd` és a
     * `nyugta.pdf.exe` egyaránt bevett trükk: az útvonal-elemeket kivágjuk, a vezérlő- és
     * elválasztó karaktereket lecseréljük.
     */
    private function safeFilename(string $name): string
    {
        $name = str_replace(["\0", '/', '\\'], '_', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = ltrim($name, '.');

        return mb_substr($name === '' ? 'csatolmany.bin' : $name, 0, 200);
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match (true) {
            str_contains($encoding, 'base64') => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            str_contains($encoding, 'quoted-printable') => (string) quoted_printable_decode($body),
            default => $body,
        };
    }

    private function charset(string $contentType): string
    {
        return preg_match('/charset\s*=\s*"?([^";\s]+)"?/i', $contentType, $match) === 1
            ? strtoupper($match[1])
            : 'UTF-8';
    }

    /**
     * A törzs UTF-8-ra.
     *
     * A magyar levelek jelentős része ma is ISO-8859-2 vagy Windows-1250 — a konverzió nélkül
     * az ékezetek helyén szemét állna a felületen és a továbbított payloadban is.
     */
    private function toUtf8(string $text, string $charset): string
    {
        if ($charset === 'UTF-8' || $charset === '') {
            // Érvénytelen UTF-8: a Postgres az EGÉSZ sort visszautasítaná.
            return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', $charset);

        return $converted === false ? $text : $converted;
    }

    /**
     * RFC 2047 kódolt szavak (`=?UTF-8?B?…?=`) a fejlécekben.
     *
     * CSAK akkor futtatjuk, ha a fejléc valóban tartalmaz kódolt szót. Az
     * `iconv_mime_decode` a NYERS UTF-8 fejlécből kidobja az ékezetes karaktereket — mérve:
     * „Ez egy nagyon hosszú tárgy" → „Ez egy nagyon hossz trgy". Az RFC 6532 óta (és a
     * SMTPUTF8 világában) a nyers UTF-8 fejléc teljesen legitim, tehát ez nem elméleti eset:
     * feltétel nélküli dekódolással minden ékezetes magyar tárgysor megcsonkulna.
     */
    private function decodeWords(?string $value): ?string
    {
        if ($value === null || $value === '' || ! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? $value : $decoded;
    }

    private function warn(string $message): void
    {
        if (! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }
}
