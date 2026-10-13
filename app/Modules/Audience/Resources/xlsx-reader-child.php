<?php

declare(strict_types=1);

/*
 | AZ IZOLÁLT XLSX-OLVASÓ GYEREKFOLYAMATA (v1.1 · modul 5, 10. review-kör, D220–D223).
 |
 | Az `IsolatedXlsxRowReader` indítja (`proc_open`), Linuxon `ulimit -v` címtér-limittel, mindenhol
 | `-d memory_limit`-tel és falióra-timeouttal. CSAK OLVAS: Laravel nélkül fut (nincs adatbázis-
 | kapcsolat, nincs hálózat, nincs naplózás), csak a Composer autoloadert tölti be.
 |
 | Protokoll (stdout, NDJSON, soronként egy JSON):
 |   {"l": <sorszám>, "c": [<cellák>]}   — egy sor, ugyanaz, amit a folyamaton belüli olvasó adna;
 |   {"error": "<ok>"}                    — kezelt hiba (pl. `rows`, `columns`, `format`), exit 3;
 |   {"done": true, "rows": <n>}          — a SIKERES vég. Ha hiányzik (fatal, OOM-kill, signal),
 |                                          a szülő a kimenetet csonkának tekinti.
 |
 | Bemenet: egyetlen argumentum, JSON: path, sheet, temp, maxColumns, maxCell, maxRows, delay.
 | A `delay` csak teszt-kapcsoló: a szülő kizárólag `runningUnitTests()` alatt küldi.
 */

use App\Modules\Audience\Services\Import\ImportLimitExceeded;
use App\Modules\Audience\Services\Import\XlsxRowReader;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$options = json_decode((string) ($argv[1] ?? ''), true);
$out = fopen('php://stdout', 'wb');

$emit = static function (array $payload) use ($out): void {
    fwrite($out, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
};

if (! is_array($options) || ! is_string($options['path'] ?? null)) {
    $emit(['error' => 'format']);
    exit(3);
}

// A libxml memória-HIÁNYA (a címtér-limit elérése a `memory_limit`-en kívüli DOM-építésben, pl. `expand()`)
// nem fatal: figyelmeztetés + az openspout kivétele. Ezt ERŐFORRÁS-hibaként jelezzük (nem formátum-hibaként).
$outOfMemory = false;
set_error_handler(static function (int $severity, string $message) use (&$outOfMemory): bool {
    if (stripos($message, 'out of memory') !== false || stripos($message, 'Allowed memory size') !== false) {
        $outOfMemory = true;
    }

    return false;
});

if ((int) ($options['delay'] ?? 0) > 0) {
    sleep((int) $options['delay']);
}

try {
    $reader = new XlsxRowReader(
        $options['path'],
        is_string($options['sheet'] ?? null) ? $options['sheet'] : null,
        is_string($options['temp'] ?? null) ? $options['temp'] : null,
        (int) $options['maxColumns'],
        (int) $options['maxCell'],
        (int) $options['maxRows'],
    );

    $count = 0;

    foreach ($reader->rows() as $line => $cells) {
        $emit(['l' => $line, 'c' => $cells]);
        $count++;
    }

    $emit(['done' => true, 'rows' => $count]);
    exit(0);
} catch (ImportLimitExceeded $e) {
    $emit(['error' => $e->reason]);
    exit(3);
} catch (Throwable $e) {
    // Az openspout olvasási / formátum-hibája: az üzenet nem utazik (cím, útvonal lehet benne). A libxml
    // memória-hiánya (címtér-limit) erőforrás-hiba.
    $exhausted = $outOfMemory || stripos($e->getMessage(), 'out of memory') !== false;
    $emit(['error' => $exhausted ? 'resources' : 'format']);
    exit(3);
}
