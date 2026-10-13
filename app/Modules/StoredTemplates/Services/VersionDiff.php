<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Services;

use Closure;

/**
 * KÉT SABLON-VERZIÓ SORALAPÚ ÖSSZEHASONLÍTÁSA.
 *
 * ## Miért a szerveren, és miért nem egy npm-csomaggal a böngészőben
 *
 * Mert a sablon TÖRZSE akár egy megabájt is lehet (`/v1/templates` ennyit enged), és a
 * szerkesztetlen HTML-t egy böngészőbe töltve a diff a fő szálon számolódna. A szerveren
 * viszont ugyanaz a kimenet tesztelhető is — egy „mit változtattam?" kérdésre adott rossz
 * válasz itt konkrét kárt okoz: a verziók MEGVÁLTOZTATHATATLANOK, tehát a felhasználó a
 * diff alapján dönti el, kell-e új verzió.
 *
 * ## Az ELSŐ változat MEMÓRIÁRA FUTOTT — és a teszt ezt nem vette észre
 *
 * A klasszikus LCS `O(n·m)` PHP-tömböt kér. Az éles `memory_limit` 128 MB (a `docker-compose`
 * és az FPM is ennyit ad), a TESZT-futásé viszont 512 MB (`phpunit.xml`, a MIME-parser
 * miatt) — vagyis a „4000 sor még belefér" korlát a teszten átment, éles oldalbetöltésen
 * pedig 2600 sor környékén FATAL hibát adott. És nem is akárhol: a verziólistás oldal
 * MAGÁTÓL számol diffet, tehát a sablon verziólistája ÉS az „Aktiválás" gomb is elérhetetlen
 * lett volna — pont az a visszaállítási út, amiért a képernyő készült.
 *
 * A javítás három rétegű, és mindhárom a VALÓDI használatra van szabva:
 *
 *  0. **BEMENETI PLAFON, SPLIT ELŐTT.** A sorokra bontás MAGA is memória: 300 000 sor két
 *     oldalon már százmegás nagyságrend. A sorszámot ezért `substr_count()` méri, MIELŐTT
 *     bármit felbontanánk — ez az egyetlen réteg, ami a fájl MÉRETÉRE néz.
 *  1. **KÖZÖS ELEJE-VÉGE LEVÁGÁSA.** Két egymást követő sablon-verzió jellemzően néhány
 *     sorban tér el. A táblát csak az ELTÉRŐ középső szakaszra építjük — egy 5000 soros
 *     sablon két verziója így néhány tucat soron számolódik.
 *  2. **A KORLÁT A KÖZÉPSŐ SZAKASZRA vonatkozik**, és a PROCESSZ szabad memóriájából
 *     származik, nem egy kitalált számból. Ha nem fér bele, a válasz „nem futott le" —
 *     nem üres diff, és nem fatal.
 *  3. **KONTEXTUSOS KIMENET — ÉS A LEVÁGOTT RÉSZ SOSEM ÉPÜL FEL.** A változatlan sorok
 *     közül csak a változás körüli néhány megy ki; a kihagyott szakaszok egy összevont
 *     sorként jelennek meg. Ez nem csak a válasz méretéről szól: az első javítás a
 *     levágott prefixet és suffixet VISSZAÉPÍTETTE a kimeneti tömbbe, mielőtt összevonta
 *     volna — a security-review ezt mérte meg: 300 000 soros (600 kB-os) sablonpár, egy
 *     sor eltéréssel, NÉMA PROCESSZ-HALÁL (nem elkapható fatal, FPM alatt 502 és
 *     worker-halál), miközben a `regionLimit()` végig 1300-at adott, tehát a `truncated`
 *     ág SOHA nem sült el. Ma a memóriaigény a VÁLTOZÁSOK számával skálázik, nem a fájl
 *     méretével.
 */
final class VersionDiff
{
    /** Ennyi VÁLTOZATLAN sor látszik a változás körül. */
    public const CONTEXT = 3;

    /**
     * Az LCS-tábla EGY cellájának mért memóriaigénye bájtban.
     *
     * A PHP tömb-cella (packed int array) mérve ~8 bájt, plusz a soronkénti tömb-fejléc; a
     * konzervatív 16 a mérés kétszerese. Nem „biztos, ami biztos": a diff egy WEBES
     * kérésben fut, ahol rajta kívül is van élő adat, és a túllépés nem lassulás, hanem
     * FATAL hiba.
     */
    private const BYTES_PER_CELL = 16;

    /**
     * A tábla legfeljebb ekkora hányadát kaphatja a SZABAD memóriának.
     *
     * A maradék a kérés többi részéé (Eloquent-modellek, Inertia-propok, a sablon két
     * törzse maga).
     */
    private const MEMORY_BUDGET = 0.25;

    /** Abszolút felső korlát a középső szakaszra, akkor is, ha a memória többet engedne. */
    public const MAX_REGION_LINES = 2000;

    /**
     * EGY BEMENETI SOR mért memóriaigénye a felbontott tömbben, bájtban.
     *
     * Egy rövid sor is `zval` + hash-bejegyzés; a mérés 80–110 bájt között szór, a 128 a
     * felső érték fölött van. A `substr_count()` ezzel a szorzóval dönt még a `preg_split`
     * ELŐTT — utána már késő: a felbontás maga a memóriafogyasztó lépés.
     */
    private const INPUT_BYTES_PER_LINE = 128;

    /** Abszolút felső korlát a BEMENET sorszámára (oldalanként). */
    public const MAX_INPUT_LINES = 60000;

    /**
     * D297 · MEMÓRIA-SZONDA (opcionális): a foglalt memória bájtban. Alapból a valódi
     * `memory_get_usage(true)`; injektálva a korlátszámítás determinisztikus (a teszt egyszer
     * rögzíti, így a kód és az állítás ugyanazzal a korláttal számol — a határ pontos).
     *
     * @param  (Closure(): int)|null  $memoryUsage
     */
    public function __construct(private readonly ?Closure $memoryUsage = null) {}

    /**
     * @return array{truncated: bool, identical: bool, limit: int, lines: list<array{type: string, left: ?int, right: ?int, text: string, skipped?: int, from?: ?int, to?: ?int}>}
     */
    public function compare(string $before, string $after): array
    {
        $limit = $this->regionLimit();

        /*
         | 0. BEMENETI PLAFON — A FELBONTÁS ELŐTT.
         |
         | A `preg_split()` maga a memóriafogyasztó lépés: 300 000 sor két oldalon
         | százmegás nagyságrend, és ez a `regionLimit()`-en KÍVÜL van (az a táblára néz).
         | A sorszámot ezért olcsón, felbontás nélkül mérjük.
         */
        $inputLimit = $this->inputLimit();

        if ($this->lineCount($before) > $inputLimit || $this->lineCount($after) > $inputLimit) {
            return ['truncated' => true, 'identical' => false, 'limit' => $limit, 'lines' => []];
        }

        $left = $this->lines($before);
        $right = $this->lines($after);

        // 1. KÖZÖS ELEJE: ameddig a két oldal sorról sorra egyezik.
        $prefix = 0;
        $leftCount = count($left);
        $rightCount = count($right);
        $max = min($leftCount, $rightCount);

        while ($prefix < $max && $left[$prefix] === $right[$prefix]) {
            $prefix++;
        }

        // 2. KÖZÖS VÉGE: hátulról ugyanígy, de a már levágott elejébe nem lóghat bele.
        $suffix = 0;

        while (
            $suffix < $max - $prefix
            && $left[$leftCount - 1 - $suffix] === $right[$rightCount - 1 - $suffix]
        ) {
            $suffix++;
        }

        $leftMiddle = array_slice($left, $prefix, $leftCount - $prefix - $suffix);
        $rightMiddle = array_slice($right, $prefix, $rightCount - $prefix - $suffix);

        if (count($leftMiddle) > $limit || count($rightMiddle) > $limit) {
            return ['truncated' => true, 'identical' => false, 'limit' => $limit, 'lines' => []];
        }

        // AZ „NINCS ELTÉRÉS" ÁLLAPOT KÜLÖN ÁLLÍTÁS.
        //
        // Két azonos verzióra a kontextusos kimenet EGYETLEN „kihagyva" sor lenne, és a
        // felhasználónak abból kellene kikövetkeztetnie, hogy nem változott semmi. Ezen a
        // képernyőn ez pont a rossz irányba téveszt: a verziók megváltoztathatatlanok, és
        // a diff alapján dől el, kell-e új verzió.
        if ($leftMiddle === [] && $rightMiddle === []) {
            return ['truncated' => false, 'identical' => true, 'limit' => $limit, 'lines' => []];
        }

        /*
         | A LEVÁGOTT ELEJE-VÉGE SOSEM ÉPÜL FEL EGÉSZBEN.
         |
         | Csak a változás KÖRNYEZETE kell belőle (`CONTEXT` sor), a többi egyetlen
         | „kihagyva" sor. Az első javítás itt hibázott: visszaépítette mind, és a
         | `withContext()` utólag dobta el — a memóriacsúcs addigra megvolt.
         */
        $lines = [];

        $contextBefore = min($prefix, self::CONTEXT);

        if ($prefix > $contextBefore) {
            $lines[] = $this->skippedLine(1, $prefix - $contextBefore);
        }

        for ($i = $prefix - $contextBefore; $i < $prefix; $i++) {
            $lines[] = ['type' => 'same', 'left' => $i + 1, 'right' => $i + 1, 'text' => $left[$i]];
        }

        $lines = array_merge($lines, $this->withContext($this->walk($leftMiddle, $rightMiddle, $prefix)));

        $contextAfter = min($suffix, self::CONTEXT);

        for ($i = 0; $i < $contextAfter; $i++) {
            $leftLine = $leftCount - $suffix + $i;
            $rightLine = $rightCount - $suffix + $i;

            $lines[] = [
                'type' => 'same',
                'left' => $leftLine + 1,
                'right' => $rightLine + 1,
                'text' => $left[$leftLine],
            ];
        }

        if ($suffix > $contextAfter) {
            $lines[] = $this->skippedLine($leftCount - $suffix + $contextAfter + 1, $leftCount);
        }

        return ['truncated' => false, 'identical' => false, 'limit' => $limit, 'lines' => $lines];
    }

    /**
     * A BEMENET sorszám-plafonja — a szabad memóriából, két oldalra.
     *
     * Ez a réteg a FÁJL MÉRETÉRE néz, nem a különbségre: a `regionLimit()` a táblát fogja,
     * ez a felbontott sor-tömböket.
     */
    public function inputLimit(): int
    {
        $limit = $this->memoryLimitBytes();

        if ($limit <= 0) {
            return self::MAX_INPUT_LINES;
        }

        $available = max(0, $limit - $this->memoryUsage());
        $lines = (int) floor($available * self::MEMORY_BUDGET / (2 * self::INPUT_BYTES_PER_LINE));

        return max(0, min(self::MAX_INPUT_LINES, $lines));
    }

    /**
     * Sorszám FELBONTÁS NÉLKÜL — a felbontás maga a drága lépés.
     *
     * A CRLF EGY sortörés, nem kettő. A naiv `\n` + `\r` összeg egy Windows-szerkesztőből
     * érkező sablon plafonját MEGFELEZTE: mérve a 30 001 soros CRLF-változat `truncated`-et
     * kapott, miközben a bitre azonos LF-változat lefutott. A becslés így is konzervatív
     * marad (soha nem becsül alá), csak nem bünteti a sortörés-stílust.
     */
    private function lineCount(string $body): int
    {
        return substr_count($body, "\n")
            + substr_count(str_replace("\r\n", '', $body), "\r")
            + 1;
    }

    /**
     * A KÖZÉPSŐ SZAKASZ korlátja a PROCESSZ szabad memóriájából.
     *
     * Nem konstans: a `memory_limit` környezetenként más (éles worker 128M, teszt-futás
     * 512M), és egy beégetett szám pont a szűkebb környezetben bukna el — ott, ahol a
     * felhasználó van.
     */
    public function regionLimit(): int
    {
        $limit = $this->memoryLimitBytes();

        if ($limit <= 0) {
            // KORLÁTLAN `memory_limit` (CLI): akkor is marad az abszolút plafon — egy
            // 20 000 soros diff a böngészőben értelmezhetetlen, akkor is, ha elfér.
            return self::MAX_REGION_LINES;
        }

        $available = max(0, $limit - $this->memoryUsage());
        $cells = (int) floor($available * self::MEMORY_BUDGET / self::BYTES_PER_CELL);

        // A tábla NÉGYZETES: n sor n×n cellát kér.
        $lines = (int) floor(sqrt(max(0, $cells)));

        return max(0, min(self::MAX_REGION_LINES, $lines));
    }

    private function memoryUsage(): int
    {
        return $this->memoryUsage !== null ? (int) ($this->memoryUsage)() : memory_get_usage(true);
    }

    private function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return 0;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /** @return list<string> */
    private function lines(string $body): array
    {
        return preg_split("/\r\n|\n|\r/", $body) ?: [];
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<array{type: string, left: ?int, right: ?int, text: string}>
     */
    private function walk(array $left, array $right, int $offset): array
    {
        $n = count($left);
        $m = count($right);

        // LCS-hossz tábla. A visszafejtés ebből olvassa ki, melyik sor közös.
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $table[$i][$j] = $left[$i] === $right[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        $lines = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($left[$i] === $right[$j]) {
                $lines[] = ['type' => 'same', 'left' => $offset + $i + 1, 'right' => $offset + $j + 1, 'text' => $left[$i]];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $lines[] = ['type' => 'removed', 'left' => $offset + $i + 1, 'right' => null, 'text' => $left[$i]];
                $i++;
            } else {
                $lines[] = ['type' => 'added', 'left' => null, 'right' => $offset + $j + 1, 'text' => $right[$j]];
                $j++;
            }
        }

        while ($i < $n) {
            $lines[] = ['type' => 'removed', 'left' => $offset + $i + 1, 'right' => null, 'text' => $left[$i]];
            $i++;
        }

        while ($j < $m) {
            $lines[] = ['type' => 'added', 'left' => null, 'right' => $offset + $j + 1, 'text' => $right[$j]];
            $j++;
        }

        return $lines;
    }

    /**
     * ÖSSZEVONT SZAKASZ — SAJÁT `from`/`to` kulccsal.
     *
     * A `left`/`right` a többi soron a KÉT OLDAL sorszáma; ha a kihagyás ugyanezt a két
     * mezőt tartományként használná, két különböző jelentés lakna egy néven — a felület
     * pedig előbb-utóbb az egyiket a másiknak nézné.
     *
     * @return array{type: string, left: ?int, right: ?int, text: string, skipped: int, from: ?int, to: ?int}
     */
    private function skippedLine(?int $from, ?int $to): array
    {
        return [
            'type' => 'skipped',
            'left' => null,
            'right' => null,
            'text' => '',
            'skipped' => $from !== null && $to !== null ? $to - $from + 1 : 0,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * VÁLTOZÁS + KÖRNYEZET, a hosszú változatlan szakaszok összevonva.
     *
     * A kihagyás nem rejtőzik el: `skipped` típusú sorként jelenik meg a kihagyott sorok
     * számával. Egy csendben elhagyott szakasz azt sugallná, hogy ott nem volt semmi.
     *
     * @param  list<array{type: string, left: ?int, right: ?int, text: string}>  $lines
     * @return list<array{type: string, left: ?int, right: ?int, text: string, skipped?: int}>
     */
    private function withContext(array $lines): array
    {
        $keep = [];

        foreach ($lines as $index => $line) {
            if ($line['type'] === 'same') {
                continue;
            }

            for ($i = $index - self::CONTEXT; $i <= $index + self::CONTEXT; $i++) {
                if ($i >= 0 && $i < count($lines)) {
                    $keep[$i] = true;
                }
            }
        }

        $out = [];
        $skipped = 0;
        $from = null;
        $to = null;

        foreach ($lines as $index => $line) {
            if (isset($keep[$index])) {
                if ($skipped > 0) {
                    // A KIHAGYOTT SZAKASZ SORTARTOMÁNYA is kimegy — az unified diff
                    // `@@ -119,3 +119,3 @@` fejléce pont ezért van. Nélküle a sorszám-oszlop
                    // 9996-ról 9997-re ugrik magyarázat nélkül, és az olvasó azt hiszi,
                    // hogy a két oldal elcsúszott.
                    $out[] = $this->skippedLine($from, $to);
                    $skipped = 0;
                    $from = null;
                    $to = null;
                }

                $out[] = $line;

                continue;
            }

            // A KIHAGYOTT szakasz csak `same` sorokból állhat (a `keep` minden változást
            // megtart), tehát a bal oldali sorszám mindig kitöltött — de a `??=`/`??` őr
            // marad, hogy egy jövőbeli típus se csússzon `null`-ra észrevétlenül.
            $from ??= $line['left'];
            $to = $line['left'] ?? $to;
            $skipped++;
        }

        if ($skipped > 0) {
            $out[] = $this->skippedLine($from, $to);
        }

        return $out;
    }
}
