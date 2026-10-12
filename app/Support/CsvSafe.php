<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSV-FORMULA-INJEKCIÓ elleni védelem — egyetlen, megosztott helyen.
 *
 * Az `fputcsv` idézőjelezése CSV-SZINTŰ: a táblázatkezelő beolvasáskor leszedi, és a `=`, `+`,
 * `-`, `@` (illetve tab/CR) kezdetű cellát KÉPLETKÉNT értékeli — a DDE-változat
 * parancsvégrehajtás az EXPORTÁLÓ gépén. A szabvány védekezés az aposztróf-prefix: a
 * táblázatkezelőnek azt jelenti, „ez szöveg"; az érték maga olvasható marad.
 *
 * A `ValidationController` és az `AnalyticsExport` saját, privát másolatot tart ugyanerről;
 * az ÚJ kód ezt hívja, hogy a szabály egy helyen javítható legyen.
 */
final class CsvSafe
{
    public static function cell(string $value): string
    {
        // Három támadási alak:
        //  1. a cella ELEJÉN `= + - @` (vezető szóköz/tab után is — a táblázatkezelők levágják);
        //  2. vezető tab / CR / újsor;
        //  3. ELVÁLASZTÓ-ELTOLÁS: `x;=HYPERLINK(…)` — a magyar (pontosvesszős) Excel a `;` mentén
        //     új cellára bontja, és a második cella `=`-lel kezdődik. Ezért az elválasztó (`, ; tab`)
        //     utáni (szóközös) `= + - @` is képlet-gyanús.
        $lead = preg_match('/^\s*[=+\-@]|^[\t\r\n]/', $value) === 1;

        // A cella BELSEJÉBEN is semlegesítünk: a magyar Excel a `;` mentén (a lista-elválasztó)
        // új cellára bont, és a második cella `=`-lel kezdődne — a cella elejére tett aposztróf
        // ezt nem védi. Az elválasztó utáni (szóközös) jelet aposztróffal szakítjuk meg.
        $inner = preg_replace('/([,;\t]\s*)([=+\-@])|([\r\n]\s*)([=+\-@])/', "$1$3'$2$4", $value) ?? $value;

        $dangerous = $lead || $inner !== $value;
        $value = $inner;

        return $dangerous ? "'".$value : $value;
    }
}
