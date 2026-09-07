<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

use App\Modules\Inbound\Data\ParsedAttachment;
use App\Modules\Inbound\Data\ParsedMail;
use RuntimeException;

/**
 * A BEJÖVŐ MIME értelmezése — korlátok között (36. kritérium).
 *
 * Ez a kódbázis legveszélyesebb bemenete: a tartalmat egy ISMERETLEN harmadik fél írta, és
 * semmi nem garantálja, hogy jóhiszemű. A parser-sérülékenységek klasszikus osztályai —
 * mélységi rekurzió, exponenciális részszám, kvadratikus visszalépés — mind abból élnek,
 * hogy valamelyik dimenzió korlátlan maradt.
 *
 * ÖT KORLÁT, mindegyik külön dimenzióra:
 *  1. MÉLYSÉG (`max_depth`): a beágyazott `message/rfc822`/`multipart` matrjoska;
 *  2. RÉSZSZÁM (`max_parts`): az „ezer üres rész" ugyanúgy DoS, mint egy mély fa;
 *  3. IDŐ (`max_seconds`): falióra-korlát, ami a fenti kettőtől függetlenül is megfog egy
 *     olyan bemenetet, amire nem gondoltunk;
 *  4. MEMÓRIA (`max_memory_bytes`): az idő önmagában NEM elég. Mérve: egy 24 MB-os levél
 *     (a fogadási limit ALATT, tehát az MX átveszi) egyetlen hosszú fejléccel 211 MB
 *     csúcsot vitt, és a 128 MB-os `memory_limit`-en FATAL ERRORRAL állt meg — az pedig nem
 *     `Throwable`, tehát a job `catch` ága le sem futott: az üzenet örökre `received`
 *     maradt, a worker meghalt. Pontosan az, amit a 36. kritérium tilt;
 *  5. FEJLÉC-BLOKK (`max_header_bytes`): saját korlát, mert a fejléc a törzstől függetlenül
 *     is lehet 25 MB.
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

    /** Ennél többet ez a parse nem foglalhat — a FATAL ERROR nem elkapható. */
    private int $memoryCeiling = 0;

    /** A legkisebb értelmes munkaterület — egy pár kilobájtos levélhez is kell ennyi. */
    private const MIN_WORKING_SET = 1_048_576;

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<ParsedAttachment> */
    private array $attachments = [];

    /**
     * A memória-plafon — a PROCESSZ limitjéhez igazítva.
     *
     * A konfigurált érték önmagában hazug lehet: ha a `max_memory_bytes` (256 MB) MAGASABB a
     * `memory_limit`-nél (pl. az alap 128 MB), a saját plafonunk sosem sül el, és a FATAL
     * ERROR — ami nem `Throwable`, tehát a job `catch` ága nem fogja — visszahozza az egész
     * problémát. Ezért a kettő közül a SZIGORÚBBAT vesszük, biztonsági kerettel.
     */
    private function ceiling(int $inputBytes): int
    {
        $usage = memory_get_usage(true);
        $configured = (int) config('inbound.parser.max_memory_bytes');
        $limit = $this->processLimitBytes();

        if ($limit <= 0) {
            return $usage + $configured;
        }

        // A plafon NÖVEKEDÉSI KERET, nem abszolút határ — de a MARADÉK helyre szorítva.
        //
        // Mindkét naiv változat rossz, és mindkettőt megmértük:
        //  - „jelenlegi + 256 MB": egy 128 MB-os limiten 140 MB-os plafont ad, tehát a saját
        //    védelmünk sosem sül el, és a FATAL ERROR (ami nem `Throwable`) megöli a workert;
        //  - „a limit 75%-a" abszolút határként: ha a hosszan élő worker MÁR 100 MB-ot
        //    használ, a plafon a jelenlegi állapot ALATT van, és a parser AZONNAL megszakít
        //    — csendben, üres eredménnyel. Mérve: a soros teszt-futásban a bejövő tesztek
        //    fele így „nulla csatolmányt" talált, miközben a kód jó volt.
        //
        // A 15% tartalék azért kell, mert a `.=` újrafoglalás egyetlen lépésben átmásolja a
        // teljes puffert (mérve: 18 MB-os foglalás egy 16 MB-os csatolmánynál).
        $headroom = (int) ($limit * 0.85) - $usage;

        // A KÖVETELMÉNY a BEMENETHEZ mért, nem fix: egy kétkilobájtos levélhez nem kell
        // nyolc megabájt, egy 25 MB-oshoz viszont kevés lenne. A szorzó kettő, mert a parse
        // legalább a dekódolt tartalmat és a részekre vágott másolatokat is tartja.
        $required = max(self::MIN_WORKING_SET, $inputBytes * 2);

        if ($headroom < $required) {
            // A KÖRNYEZET a hibás, nem a levél. Ezt HANGOSAN mondjuk ki: a job elbukik, az
            // üzenet `failed` lesz, a worker újraindul — szemben a csendes, üres parse-szal,
            // ami után senki nem tudná, miért nincs a levélben semmi.
            throw new RuntimeException(sprintf(
                'A bejövő MIME értelmezéséhez nincs elég memória: %d bájt szabad, %d kellene '
                .'ehhez a %d bájtos levélhez. Emeld a worker `memory_limit`-jét (a compose-ban 512M).',
                max(0, $headroom),
                $required,
                $inputBytes,
            ));
        }

        return $usage + min($configured, $headroom);
    }

    private function processLimitBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return 0;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1_073_741_824,
            'm' => $value * 1_048_576,
            'k' => $value * 1024,
            default => $value,
        };
    }

    public function parse(string $raw): ParsedMail
    {
        $this->parts = 0;
        $this->warnings = [];
        $this->attachments = [];
        $this->deadline = microtime(true) + (float) config('inbound.parser.max_seconds');
        $this->memoryCeiling = $this->ceiling(strlen($raw));

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

    /**
     * @return array<string, string>
     */
    private function headers(string $block): array
    {
        $maxBytes = (int) config('inbound.parser.max_header_bytes');

        if (strlen($block) > $maxBytes) {
            // A fejléc-blokk ÖNMAGÁBAN is lehet 25 MB. A `preg_split` egy ekkora blokkra
            // sok százmegányi tömböt épít — a vágás ELŐBB jön, mint a feldolgozás.
            $this->warn('A fejléc-blokk hosszabb, mint az engedélyezett korlát.');

            $block = substr($block, 0, $maxBytes);
        }

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

            // A `Received:` sorok SZÁMA a levél útjának hossza — ez a hurok-védelem
            // szabványos, NEM STRIPPELHETŐ tartaléka. Minden MTA hozzáad egyet, és a saját
            // `X-VentMail-Hops` fejlécünkkel ellentétben ezt egy közbeiktatott továbbító sem
            // szedheti le anélkül, hogy a levél útja követhetetlenné válna.
            if ($name === 'received') {
                $headers['x-ventmail-received-count'] = (string) (1 + (int) ($headers['x-ventmail-received-count'] ?? 0));
            }

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

        // A MEMÓRIA-plafon: a `memory_limit` túllépése FATAL ERROR, amit a hívó `catch`
        // ága NEM fog el — a worker meghalna, az üzenet pedig örökre `received` maradna.
        if (memory_get_usage(true) > $this->memoryCeiling) {
            $this->warn('Az értelmezés memória-korlátba ütközött.');

            return;
        }

        $contentType = strtolower($headers['content-type'] ?? 'text/plain');
        $disposition = strtolower($headers['content-disposition'] ?? '');

        // BEÁGYAZOTT LEVÉL (`message/rfc822`): a továbbított levél törzse és csatolmányai
        // enélkül LÁTHATATLANOK lennének a parsernek, a routernek és a vírusirtónak — pont
        // az a forma, amiben a kártevő a leggyakrabban érkezik. A mélységi korlát ugyanaz.
        if (str_starts_with($contentType, 'message/rfc822')) {
            $decodedInner = $this->decodeBody($body, strtolower($headers['content-transfer-encoding'] ?? ''));

            [$innerHeaders, $innerBody] = $this->split($decodedInner);

            $this->walk($this->headers($innerHeaders), $innerBody, $depth + 1, $plain, $html);

            return;
        }

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

        // Csatolmány: vagy explicit `attachment`, vagy van fájlneve.
        $filename = $this->filename($headers);

        $isAttachment = str_contains($disposition, 'attachment') || $filename !== null;

        // A PLAFON FÖLÖTTI csatolmányt EL SEM DEKÓDOLJUK. A hívó úgyis elutasítja a mérete
        // miatt — a dekódolás csak a memóriát vinné el ugyanazért az eredményért. A
        // base64 négy bájtból hármat ad, tehát a kódolt méret ~4/3-a a nyersnek.
        $ceiling = (int) (config('inbound.attachments.max_bytes') * 1.4);

        if ($isAttachment && strlen($body) > $ceiling) {
            $this->warn('A csatolmány mérete meghaladja a korlátot — nem dekódoltuk.');

            $this->attachments[] = new ParsedAttachment(
                filename: $filename ?? 'csatolmany.bin',
                contentType: trim(explode(';', $contentType)[0]),
                // ÜRES tartalom, de a MÉRET a valós: a hívó ebből dönt, és az ügyfél is
                // látja, mi érkezett.
                contents: '',
                declaredSize: strlen($body),
            );

            return;
        }

        $decoded = $this->decodeBody($body, strtolower($headers['content-transfer-encoding'] ?? ''));

        if ($isAttachment) {
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

    /**
     * A boundary CSAK SOR ELEJÉN határol (RFC 2046 §5.1.1), és KORLÁTOZOTT darabszámban.
     *
     * Két hiba javul itt egyszerre:
     *
     * 1. A naiv `explode('--'.$boundary, $body)` a határolót BÁRHOL felismeri. Mérve: egy
     *    ártatlan mondat közepére írt `X--HATAR--` hatására a parser NULLA csatolmányt talált
     *    egy háromrészes levélben — a `kartevo.exe` így soha nem került volna a vírusirtó
     *    elé, miközben a címzett levélkliense (ami a szabvány szerint sor elejét vár) szépen
     *    megmutatja. Ez a csempészés legolcsóbb formája.
     *
     * 2. Az `explode()` a RÉSZSZÁM-ELLENŐRZÉS ELŐTT építette fel a tömböt. Mérve: egy 6 MB-os,
     *    sok határolós levél a `max_parts` figyelmeztetés MELLETT is 128 MB fölé vitte a
     *    memóriát — és a `memory_limit` túllépése FATAL ERROR, amit a hívó `catch` ága nem
     *    fog el: a worker meghal, az üzenet örökre `received` marad. Ezért itt már a
     *    KERESÉSKOR megállunk a korlátnál, és offsetekkel vágunk, nem tömböt építünk.
     *
     * @return list<string>
     */
    private function segments(string $body, string $boundary): array
    {
        $pattern = '/(?:\r\n|\n|^)--'.preg_quote($boundary, '/').'([ \t]*--)?[ \t]*(?=\r\n|\n|$)/';

        if (preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $limit = (int) config('inbound.parser.max_parts');
        $segments = [];
        $previousEnd = null;

        foreach ($matches[0] as $index => [$marker, $offset]) {
            // Az ELŐZŐ határoló és a mostani közti szakasz egy rész. Az első határoló előtti
            // darab a preambulum (a MIME-t nem értő kliensek szövege) — nem MIME-rész.
            if ($previousEnd !== null) {
                // A MÁSOLÓ ciklus is nézi a plafont: minden `substr` egy új sztring, és a
                // részszám-korlát egy 6 MB-os levélnél is elkésett volna.
                if (memory_get_usage(true) > $this->memoryCeiling) {
                    $this->warn('Az értelmezés memória-korlátba ütközött.');

                    return $segments;
                }

                $segments[] = ltrim(substr($body, $previousEnd, $offset - $previousEnd), "\r\n");

                if (count($segments) >= $limit) {
                    $this->warn('A MIME-részek száma meghaladta a korlátot.');

                    return $segments;
                }
            }

            // A ZÁRÓ határoló (`--HATAR--`) után minden epilógus.
            if (($matches[1][$index][0] ?? '') !== '') {
                return $segments;
            }

            $previousEnd = $offset + strlen($marker);
        }

        if ($previousEnd !== null) {
            // Lezáratlan multipart: ami maradt, még lehet rész — a malformált levél sem
            // veszhet el csendben.
            $segments[] = ltrim(substr($body, $previousEnd), "\r\n");
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

    /**
     * A rész dekódolása — MÁSOLAT-TAKARÉKOSAN.
     *
     * A naiv `base64_decode(preg_replace('/\s+/', '', $body))` HÁROM teljes méretű másolatot
     * tart egyszerre: a nyers törzset, a whitespace nélküli változatot és a dekódolt
     * eredményt. Mérve, 128 MB-os `memory_limit` mellett: egy 16 MB-os base64 csatolmány
     * FATAL ERRORT adott — az pedig nem `Throwable`, tehát a job `catch` ága le sem futott,
     * a worker meghalt, az üzenet örökre `received` maradt. És ez nem támadás: egy
     * hétköznapi nagy PDF-melléklet is ekkora.
     *
     * Ezért blokkokban dolgozunk: a whitespace nélküli teljes másolat sosem áll elő.
     */
    private function decodeBody(string $body, string $encoding): string
    {
        if (str_contains($encoding, 'base64')) {
            return $this->decodeBase64($body);
        }

        if (str_contains($encoding, 'quoted-printable')) {
            return (string) quoted_printable_decode($body);
        }

        return $body;
    }

    private function decodeBase64(string $body): string
    {
        $out = '';
        $carry = '';
        $length = strlen($body);

        for ($offset = 0; $offset < $length; $offset += 65536) {
            if (microtime(true) > $this->deadline || memory_get_usage(true) > $this->memoryCeiling) {
                $this->warn('A dekódolás korlátba ütközött.');

                return $out;
            }

            // A KÖVETKEZŐ foglalás is beleférjen: a `.=` újrafoglaláskor a teljes puffert
            // átmásolja, tehát a csúcs a duplája lehet.
            if (memory_get_usage(true) + strlen($out) > $this->memoryCeiling) {
                $this->warn('A dekódolás memória-korlátba ütközött.');

                return $out;
            }

            $chunk = $carry.(string) preg_replace('/\s+/', '', substr($body, $offset, 65536));

            // A base64 NÉGYES csoportokban dekódolható: a maradékot átvisszük a következő
            // blokkba, különben a blokkhatáron csonka csoport keletkezne.
            $usable = intdiv(strlen($chunk), 4) * 4;
            $carry = substr($chunk, $usable);

            $out .= (string) base64_decode(substr($chunk, 0, $usable), false);
        }

        return $out.($carry === '' ? '' : (string) base64_decode($carry, false));
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
