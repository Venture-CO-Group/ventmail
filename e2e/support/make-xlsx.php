<?php

/*
 | E2E-SEGÉD: XLSX-fixture generálása futásidőben (nem commitolt bináris).
 |
 | Használat: php make-xlsx.php <kimeneti.xlsx> <sheets.json>
 | A JSON: {"Munkalap neve": [["cella", ...], ...], ...}
 |
 | A Playwright (Node) nem tud XLSX-et írni külső függőség nélkül; a repóban viszont ott az
 | `openspout/openspout`, amit az import maga is használ — ugyanazzal írjuk a tesztfájlt.
 */

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

[, $out, $json] = $argv;

$sheets = json_decode((string) file_get_contents($json), true, 512, JSON_THROW_ON_ERROR);

$writer = new Writer;
$writer->openToFile($out);

$first = true;

foreach ($sheets as $name => $rows) {
    $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
    $sheet->setName((string) $name);
    $first = false;

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
}

$writer->close();
