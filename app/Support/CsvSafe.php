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
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
