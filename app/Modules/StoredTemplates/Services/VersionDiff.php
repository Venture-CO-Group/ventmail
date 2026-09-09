<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Services;

/**
 * KÉT SABLON-VERZIÓ SORALAPÚ ÖSSZEHASONLÍTÁSA.
 *
 * ## Miért a szerveren, és miért nem egy npm-csomaggal a böngészőben
 *
 * Mert a sablon TÖRZSE akár egy megabájt is lehet (a validáció ennyit enged), és a
 * szerkesztetlen HTML-t egy böngészőbe töltve a diff a fő szálon számolódna. A szerveren
 * viszont ugyanaz a kimenet tesztelhető is — egy „mit változtattam?" kérdésre adott rossz
 * válasz itt konkrét kárt okoz: a verziók MEGVÁLTOZTATHATATLANOK, tehát a felhasználó a
 * diff alapján dönti el, kell-e új verzió.
 *
 * ## Az algoritmus: LCS, korláttal
 *
 * A leghosszabb közös részsorozat (Myers helyett a klasszikus DP) O(n·m) memóriát kérne,
 * ami két 20 000 soros törzsnél 400 millió cella — ezért a bemenet SORSZÁMBAN korlátozott,
 * és a korlát fölött a diff „túl nagy" jelzést ad, nem hazudik és nem is fagy le.
 */
final class VersionDiff
{
    /** A még összehasonlítható sorszám. Fölötte a felület figyelmeztet. */
    public const MAX_LINES = 4000;

    /**
     * @return array{truncated: bool, lines: list<array{type: string, left: ?int, right: ?int, text: string}>}
     */
    public function compare(string $before, string $after): array
    {
        $left = $this->lines($before);
        $right = $this->lines($after);

        if (count($left) > self::MAX_LINES || count($right) > self::MAX_LINES) {
            return ['truncated' => true, 'lines' => []];
        }

        return ['truncated' => false, 'lines' => $this->walk($left, $right)];
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
    private function walk(array $left, array $right): array
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
                $lines[] = ['type' => 'same', 'left' => $i + 1, 'right' => $j + 1, 'text' => $left[$i]];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $lines[] = ['type' => 'removed', 'left' => $i + 1, 'right' => null, 'text' => $left[$i]];
                $i++;
            } else {
                $lines[] = ['type' => 'added', 'left' => null, 'right' => $j + 1, 'text' => $right[$j]];
                $j++;
            }
        }

        while ($i < $n) {
            $lines[] = ['type' => 'removed', 'left' => $i + 1, 'right' => null, 'text' => $left[$i]];
            $i++;
        }

        while ($j < $m) {
            $lines[] = ['type' => 'added', 'left' => null, 'right' => $j + 1, 'text' => $right[$j]];
            $j++;
        }

        return $lines;
    }
}
