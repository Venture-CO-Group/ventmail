<?php

declare(strict_types=1);

/*
 | AZ IZOLÁLT XLSX-OLVASÓ GYEREKFOLYAMATA (v1.1 · modul 5, 10. review-kör, D220–D223).
 |
 | Az `IsolatedXlsxRowReader` indítja (`proc_open`), Linuxon `ulimit -v` címtér-limittel, mindenhol
 | `ulimit -t` CPU-idő plafonnal, `-d memory_limit`-tel és falióra-timeouttal. CSAK OLVAS: Laravel nélkül
 | fut, csak a Composer autoloadert tölti be, és a kódja semmilyen kapcsolatot nem nyit.
 |
 | IZOLÁCIÓ (review r11 S1, D255–D256): a gyerek MINIMÁLIS környezetet kap (`PATH`, és ha van, a php.ini-
 | útvonal és a TMPDIR — titok, pl. APP_KEY, DB_PASSWORD SOHA), és a szülő 2 fölötti fd-ijét (Redis- és
 | Postgres-socket, nyitott fájlok) a shell-wrapper az `exec` ELŐTT lezárja. Ha a szülő meghal, az első
 | sikertelen írás leállítja (exit 4), a CPU-plafon pedig felső korlát (review r11 S2).
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

// Az `STDOUT` állandó (az fd 1 maga) — a `php://stdout` megnyitása új fd-t (dup) hozna létre.
$emit = static function (array $payload): void {
    // A SZÜLŐ ELTŰNT (a cső zárva — a PHP CLI a SIGPIPE-ot figyelmen kívül hagyja): azonnal kilépünk, nem
    // olvassuk végig árván a fájlt (review r11 S2).
    if (@fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n") === false) {
        exit(4);
    }
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

// TESZT-kapcsoló (csak `runningUnitTests()` alatt küldi a szülő): a gyerek KÖRNYEZETÉNEK nevei és a nyitott
// fd-k (a `/proc/self/fd` listázásához megnyitott könyvtár-fd nélkül) — az izoláció ellenőrzéséhez.
if (($options['probe'] ?? false) === true) {
    $fds = [];

    foreach (is_dir('/proc/self/fd') ? (array) scandir('/proc/self/fd') : [] as $fd) {
        if (ctype_digit((string) $fd)) {
            $target = (string) @readlink('/proc/self/fd/'.$fd);
            $fds[] = $fd.' -> '.$target;
        }
    }

    // Az env ÉRTÉKEI csak SHA-256 kivonatként mennek ki (a teszt a szülő futásidejű ál-titkát a
    // kivonattal keresi): a probe így sem ad ki értéket, de érték szerint is bizonyítható a tisztítás.
    $environment = getenv();
    $emit(['probe' => ['env' => array_keys($environment), 'digests' => array_values(array_map(static fn ($value) => hash('sha256', (string) $value), $environment)), 'fds' => $fds]]);
    exit(0);
}

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
