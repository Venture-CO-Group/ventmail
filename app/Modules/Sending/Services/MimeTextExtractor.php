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
     * @return list<string>
     */
    public function extract(string $raw): array
    {
        $texts = [];
        $budget = self::MAX_TEXT_BYTES;
        $parts = 0;

        $this->walk($raw, 0, $texts, $budget, $parts);

        return $texts;
    }

    /**
     * @param  list<string>  $texts
     */
    private function walk(string $entity, int $depth, array &$texts, int &$budget, int &$parts): void
    {
        if ($depth > self::MAX_DEPTH || $budget <= 0 || $parts >= self::MAX_PARTS) {
            return;
        }

        [$headers, $body] = $this->split($entity);

        $contentType = strtolower($headers['content-type'] ?? 'text/plain');

        if (str_starts_with($contentType, 'multipart/')) {
            $boundary = $this->boundary($headers['content-type'] ?? '');

            if ($boundary === null) {
                // Boundary nélkül a törzs értelmezhetetlen szerkezetileg — de attól még
                // tartalmazhat linket. Fail-open helyett megnézzük nyersen.
                $this->collect($body, $texts, $budget, $parts);

                return;
            }

            foreach ($this->explodeParts($body, $boundary) as $part) {
                $this->walk($part, $depth + 1, $texts, $budget, $parts);

                if ($budget <= 0 || $parts >= self::MAX_PARTS) {
                    return;
                }
            }

            return;
        }

        // Nem-szöveges rész (kép, PDF): a linkek szempontjából nem érdekes, és a
        // dekódolása csak a büdzsét enné. A `application/*` viszont lehet SVG vagy HTML,
        // ezért azt is nézzük.
        if (str_starts_with($contentType, 'image/')
            || str_starts_with($contentType, 'audio/')
            || str_starts_with($contentType, 'video/')) {
            return;
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

    private function boundary(string $contentType): ?string
    {
        if (preg_match('/boundary\s*=\s*"([^"]+)"|boundary\s*=\s*([^;\s]+)/i', $contentType, $match) !== 1) {
            return null;
        }

        $boundary = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

        return $boundary === '' ? null : $boundary;
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
