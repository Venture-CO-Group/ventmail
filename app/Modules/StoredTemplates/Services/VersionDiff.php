<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Services;

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
 *  1. **KÖZÖS ELEJE-VÉGE LEVÁGÁSA.** Két egymást követő sablon-verzió jellemzően néhány
 *     sorban tér el. A táblát csak az ELTÉRŐ középső szakaszra építjük — egy 5000 soros
 *     sablon két verziója így néhány tucat soron számolódik.
 *  2. **A KORLÁT A KÖZÉPSŐ SZAKASZRA vonatkozik**, és a PROCESSZ szabad memóriájából
 *     származik, nem egy kitalált számból. Ha nem fér bele, a válasz „nem futott le" —
 *     nem üres diff, és nem fatal.
 *  3. **KONTEXTUSOS KIMENET.** A változatlan sorok közül csak a változás körüli néhány
 *     megy ki; a kihagyott szakaszok egy összevont sorként jelennek meg. Enélkül egy
 *     4000 soros sablon diffje 4000 DOM-csomópontként és több MB-os Inertia-propként
 *     érkezett volna a böngészőbe.
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
     * @return array{truncated: bool, limit: int, lines: list<array{type: string, left: ?int, right: ?int, text: string, skipped?: int}>}
     */
    public function compare(string $before, string $after): array
    {
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

        $limit = $this->regionLimit();

        if (count($leftMiddle) > $limit || count($rightMiddle) > $limit) {
            return ['truncated' => true, 'limit' => $limit, 'lines' => []];
        }

        $middle = $this->walk($leftMiddle, $rightMiddle, $prefix);

        // A LEVÁGOTT ELEJE-VÉGE is a diff része — csak nem kell mind kiírni.
        $lines = [];

        foreach (range(0, $prefix - 1) as $index) {
            if ($prefix > 0) {
                $lines[] = ['type' => 'same', 'left' => $index + 1, 'right' => $index + 1, 'text' => $left[$index]];
            }
        }

        $lines = array_merge($lines, $middle);

        for ($i = 0; $i < $suffix; $i++) {
            $leftLine = $leftCount - $suffix + $i;
            $rightLine = $rightCount - $suffix + $i;

            $lines[] = [
                'type' => 'same',
                'left' => $leftLine + 1,
                'right' => $rightLine + 1,
                'text' => $left[$leftLine],
            ];
        }

        return ['truncated' => false, 'limit' => $limit, 'lines' => $this->withContext($lines)];
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

        $available = max(0, $limit - memory_get_usage(true));
        $cells = (int) floor($available * self::MEMORY_BUDGET / self::BYTES_PER_CELL);

        // A tábla NÉGYZETES: n sor n×n cellát kér.
        $lines = (int) floor(sqrt(max(0, $cells)));

        return max(0, min(self::MAX_REGION_LINES, $lines));
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

        foreach ($lines as $index => $line) {
            if (isset($keep[$index])) {
                if ($skipped > 0) {
                    $out[] = ['type' => 'skipped', 'left' => null, 'right' => null, 'text' => '', 'skipped' => $skipped];
                    $skipped = 0;
                }

                $out[] = $line;

                continue;
            }

            $skipped++;
        }

        if ($skipped > 0) {
            $out[] = ['type' => 'skipped', 'left' => null, 'right' => null, 'text' => '', 'skipped' => $skipped];
        }

        return $out;
    }
}
