<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

/**
 * A nyers MIME SZÖVEGES részeinek kinyerése — tartalom-vizsgálathoz.
 *
 * Miért nem regex: az első változat egy „legalább két, 60+ karakteres base64-sor" mintával
 * kereste a kódolt részeket. Két baja volt, mindkettő súlyos:
 *
 * 1. NÉGYZETES VISSZALÉPÉS. Egy hosszú base64-osztályú karakterfutam minden kezdőpozícióban
 *    végigjáratta a `{60,}` backtrackelését: 500 KB törzs = 76 s CPU, a 2 MB-os plafonon
 *    ~20 perc — szinkron, a web-workeren. Egyetlen hitelesített hívás kiütötte volna az
 *    összes workert, minden tenant számára.
 * 2. TÖRDELÉSSEL MEGKERÜLHETŐ. Az RFC 2045 a 76 karaktert felső korlátnak mondja: aki
 *    59-esre tördel vagy egy sorba ír, annak a levele egyáltalán nem ment át a kapun.
 *
 * Ezért itt SZERKEZET szerint bontunk: fejléc → `Content-Type` → boundary → részek, és a
 * rész `Content-Transfer-Encoding`-ja szerint dekódolunk. Minden lépés lineáris, és három
 * korlát fogja: mélység, rész-darabszám, összes kinyert bájt.
 *
 * FIGYELEM: ez NEM teljes MIME-parser és nem is akar az lenni. A célja, hogy a
 * tartalom-kapu ugyanazt lássa, amit a fogadó levelezőkliens megjelenít. A bejövő
 * (ismeretlen eredetű) levelek parsere külön él, sandboxban (A4).
 */
final class MimeTextExtractor
{
    /** Beágyazott multipart-mélység. Efölött nem megyünk — se mi, se a levélkliensek. */
    private const MAX_DEPTH = 5;

    /** Hány részt vizsgálunk legfeljebb (a levél lehet 1000 csatolmányos). */
    private const MAX_PARTS = 50;

    /** Az összes kinyert szöveg felső korlátja. */
    private const MAX_TEXT_BYTES = 2_097_152;

    /** Egyetlen rész dekódolás előtti mérete — efölött csak a rész elejét nézzük. */
    private const MAX_PART_BYTES = 1_048_576;

    /**
     * A levél megjeleníthető szöveges részei (dekódolva).
     *
     * A `truncated` jelzi, ha a levél kimerítette valamelyik korlátot. Ilyenkor a hívónak
     * EL KELL UTASÍTANIA: a csendes kihagyás azt jelentené, hogy az 51. rész (vagy az 1 MB
     * utáni tartalom) vizsgálat NÉLKÜL megy ki — vagyis a kapu megkerülhető azzal, hogy a
     * támadó elég összetett levelet állít össze.
     *
     * @return array{texts: list<string>, truncated: bool}
     */
    public function extract(string $raw): array
    {
        $texts = [];
        $budget = self::MAX_TEXT_BYTES;
        $parts = 0;
        $truncated = false;

        $this->walk($raw, 0, $texts, $budget, $parts, $truncated);

        return ['texts' => $texts, 'truncated' => $truncated];
    }

    /**
     * @param  list<string>  $texts
     */
    private function walk(string $entity, int $depth, array &$texts, int &$budget, int &$parts, bool &$truncated): void
    {
        if ($depth > self::MAX_DEPTH || $budget <= 0 || $parts >= self::MAX_PARTS) {
            $truncated = true;

            return;
        }

        [$headers, $body] = $this->split($entity);

        $contentType = strtolower($headers['content-type'] ?? 'text/plain');

        if (str_starts_with($contentType, 'multipart/')) {
            $boundary = $this->boundary($headers['content-type'] ?? '');

            if ($boundary === null) {
                // Boundary nélkül a törzs szerkezetileg értelmezhetetlen — de attól még
                // tartalmazhat linket, ezért nyersen megnézzük. Ha a törzs a büdzsénél
                // nagyobb, azt JELEZZÜK: ez volt az egyetlen pont, ahol a csonkolás
                // csendben maradt (2 MB fölötti törzs átment a kapun).
                if (strlen($body) > min($budget, self::MAX_PART_BYTES)) {
                    $truncated = true;
                }

                $this->collect(substr($body, 0, self::MAX_PART_BYTES), $texts, $budget, $parts);

                return;
            }

            $pieces = $this->explodeParts($body, $boundary);

            // ÜRES multipart: a boundary vagy hibás, vagy „elrabolták" (egy korábbi,
            // idézőjeles paraméter-érték tartalmazta a `boundary=` szót). Ilyenkor NEM
            // tudjuk, mi van a levélben — a fogadó viszont tudja. Fail-closed.
            if ($pieces === []) {
                $truncated = true;

                return;
            }

            foreach ($pieces as $part) {
                $this->walk($part, $depth + 1, $texts, $budget, $parts, $truncated);

                if ($budget <= 0 || $parts >= self::MAX_PARTS) {
                    $truncated = true;

                    return;
                }
            }

            return;
        }

        // Beágyazott levél: a törzse egy TELJES üzenet, saját fejlécekkel és kódolással.
        // Nem `multipart/`, tehát a bejárás korábban kihagyta — a benne lévő base64-es
        // HTML sosem dekódolódott.
        if (str_starts_with($contentType, 'message/')) {
            $this->walk($body, $depth + 1, $texts, $budget, $parts, $truncated);

            return;
        }

        // Nem-szöveges rész (kép, hang, videó): a linkek szempontjából nem érdekes, és a
        // dekódolása csak a büdzsét enné. AZ SVG KIVÉTEL: `image/` a típusa, de AKTÍV
        // tartalom (`<script>`, `javascript:` href) — azt vizsgáljuk.
        if (str_contains($contentType, 'svg')) {
            // marad: megvizsgáljuk
        } elseif (str_starts_with($contentType, 'image/')
            || str_starts_with($contentType, 'audio/')
            || str_starts_with($contentType, 'video/')) {
            return;
        }

        if (strlen($body) > self::MAX_PART_BYTES) {
            $truncated = true;
        }

        $decoded = $this->decode(substr($body, 0, self::MAX_PART_BYTES), $headers['content-transfer-encoding'] ?? '');

        $this->collect($decoded, $texts, $budget, $parts);
    }

    /**
     * @param  list<string>  $texts
     */
    private function collect(string $text, array &$texts, int &$budget, int &$parts): void
    {
        if ($text === '') {
            return;
        }

        $slice = substr($text, 0, $budget);

        $texts[] = $slice;
        $budget -= strlen($slice);
        $parts++;
    }

    /**
     * Fejléc/törzs bontás az ELSŐ üres sornál — sorvég-fajtától függetlenül.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function split(string $entity): array
    {
        if (preg_match('/\r?\n\r?\n/', $entity, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [[], $entity];
        }

        $position = $match[0][1];

        $headers = [];
        $current = null;

        foreach (preg_split('/\r?\n/', substr($entity, 0, $position)) ?: [] as $line) {
            if ($current !== null && preg_match('/^[ \t]/', $line) === 1) {
                $headers[$current] .= ' '.trim($line);

                continue;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $current = strtolower(trim($name));
            $headers[$current] = trim($value);
        }

        return [$headers, substr($entity, $position + strlen($match[0][0]))];
    }

    /**
     * A boundary PARAMÉTER-PARSERREL, nem mintakereséssel.
     *
     * A korábbi regex bárhol illeszkedett a fejlécértékben: egy `name="boundary=x"`
     * paraméter ELRABOLTA a keresést, a bontás nulla részt adott, és a levél csendben
     * „tisztának" minősült — miközben a fogadó kliens a valódi boundaryval megjelenítette
     * a benne lévő linket.
     */
    private function boundary(string $contentType): ?string
    {
        foreach ($this->parameters($contentType) as $name => $value) {
            if ($name === 'boundary' && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Fejlécérték paraméterei, az idézőjeleket tiszteletben tartva (RFC 2045 §5.1).
     *
     * @return array<string, string>
     */
    private function parameters(string $value): array
    {
        $parameters = [];
        $current = '';
        $inQuotes = false;
        $escaped = false;

        foreach (str_split($value) as $character) {
            if ($escaped) {
                $current .= $character;
                $escaped = false;

                continue;
            }

            if ($inQuotes && $character === '\\') {
                $escaped = true;

                continue;
            }

            if ($character === '"') {
                $inQuotes = ! $inQuotes;

                continue;
            }

            if ($character === ';' && ! $inQuotes) {
                $this->addParameter($parameters, $current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $this->addParameter($parameters, $current);

        return $parameters;
    }

    /** @param array<string, string> $parameters */
    private function addParameter(array &$parameters, string $chunk): void
    {
        if (! str_contains($chunk, '=')) {
            return;
        }

        [$name, $value] = explode('=', $chunk, 2);

        $parameters[strtolower(trim($name))] = trim($value);
    }

    /**
     * Részekre bontás a boundary mentén — sztring-kereséssel, nem mintaillesztéssel.
     *
     * @return list<string>
     */
    private function explodeParts(string $body, string $boundary): array
    {
        $chunks = explode('--'.$boundary, $body);

        // Az első darab a preamble, az utolsó a lezáró `--` utáni epilogue.
        array_shift($chunks);

        return array_map(
            static fn (string $chunk) => ltrim($chunk, "\r\n"),
            array_filter($chunks, static fn (string $chunk) => trim($chunk) !== '' && trim($chunk) !== '--')
        );
    }

    private function decode(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            // A base64 dekódolása SOR-alapú: a whitespace eltávolítása `str_replace`, nem
            // regex — ez a lépés fut a legnagyobb adaton.
            'base64' => (string) base64_decode(str_replace(["\r", "\n", ' ', "\t"], '', $body), strict: false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }
}
