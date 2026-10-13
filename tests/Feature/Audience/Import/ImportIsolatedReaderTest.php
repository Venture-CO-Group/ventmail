<?php

declare(strict_types=1);

use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Services\Import\ImportFileInspector;
use App\Modules\Audience\Services\Import\ImportLimitExceeded;
use App\Modules\Audience\Services\Import\ImportUploads;
use App\Modules\Audience\Services\Import\IsolatedXlsxRowReader;
use App\Modules\Audience\Services\Import\XlsxRowReader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
 | v1.1 · modul 5, 10. review-kör — D220: az openspout-olvasás KÜLÖN gyerekfolyamatban fut (címtér-
 | limit Linuxon, memory_limit, falióra-timeout). Bármely rendellenes kilépés elegáns elutasítás; a
 | szülő (a worker) él tovább, a legitim fájl sorai változatlanok.
 */

/** @var list<string> */
$GLOBALS['isoFiles'] = [];

function isoPath(string $suffix = '.xlsx'): string
{
    $path = sys_get_temp_dir().'/vm-iso-'.uniqid().$suffix;
    $GLOBALS['isoFiles'][] = $path;

    return $path;
}

/** Kézzel épített munkafüzet: a `$sheetXml` az egyetlen munkalap. */
function isoBook(string $sheetXml, int $sheets = 1): string
{
    $path = isoPath();
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');

    $nodes = '';

    for ($i = 1; $i <= $sheets; $i++) {
        $nodes .= '<sheet name="S'.$i.'" sheetId="'.$i.'" r:id="rId1"/>';
    }

    $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$nodes.'</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    return $path;
}

/** Valósághű munkafüzet az openspout íróval: szöveg, szám, tört, dátum, logikai, üres sor, záró üres cellák. */
function isoLegit(): string
{
    $path = isoPath();
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Kontaktok');

    foreach ([
        ['email', 'név', 'szám', 'dátum', 'aktív'],
        ['anna@pelda.hu', 'Anna "Annus" Kovács', 3.0, new DateTimeImmutable('2026-03-01'), true],
        ['', '', '', '', ''],
        ['bela@pelda.hu', "Béla\x07 Nagy", 3.5, new DateTimeImmutable('2026-03-01 08:15:00'), false],
        ['cecil@pelda.hu', 'Cecil', 123456789012.0, '', ''],
        ['dóra@pelda.hu', 'Dóra — ő', 0.1, 'szöveg', null],
    ] as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return $path;
}

/** @return array<int, list<string>> */
function isoRows(iterable $rows): array
{
    $out = [];

    foreach ($rows as $line => $cells) {
        $out[$line] = $cells;
    }

    return $out;
}

afterEach(function () {
    IsolatedXlsxRowReader::delayChildForTests(0);

    foreach ($GLOBALS['isoFiles'] as $path) {
        @unlink($path);
    }

    $GLOBALS['isoFiles'] = [];
});

it('D220 · a legitim fájl sorai a gyerekfolyamatból PONTOSAN ugyanazok, mint a folyamaton belüli olvasóé', function () {
    $path = isoLegit();

    $inProcess = isoRows((new XlsxRowReader($path, 'Kontaktok'))->rows());
    $isolated = isoRows((new IsolatedXlsxRowReader($path, 'Kontaktok', sys_get_temp_dir(), 30))->rows());

    expect($isolated)->toBe($inProcess)
        ->and(count($isolated))->toBe(5)
        ->and(array_keys($isolated))->toBe([1, 2, 4, 5, 6]);
});

it('D220 · a feldolgozás és a felmérés is az izolált olvasót használja', function () {
    $import = new ContactImport;
    $import->forceFill(['uuid' => (string) Str::uuid(), 'storage_path' => 'imports/1/x.xlsx', 'profile' => ['format' => 'xlsx', 'sheet' => 'S']]);

    expect(app(ImportUploads::class)->reader($import))->toBeInstanceOf(IsolatedXlsxRowReader::class);
});

it('B1(r10) · a `<row spans>` array_fill-fatalja a GYEREKET öli meg — elegáns elutasítás, a szülő él tovább', function () {
    $spans = isoBook('<worksheet><sheetData><row r="1" spans="1:30000000"><c r="A1" t="inlineStr"><is><t>email</t></is></c></row></sheetData></worksheet>');

    expect(fn () => isoRows((new IsolatedXlsxRowReader($spans, null, sys_get_temp_dir(), 30))->rows()))
        ->toThrow(fn (ImportLimitExceeded $e) => expect($e->reason)->toBe('resources'));

    // A felmérés útja is: elutasítás, nem fatal.
    expect(fn () => app(ImportFileInspector::class)->inspect($spans, 'xlsx'))
        ->toThrow(fn (ImportLimitExceeded $e) => expect($e->reason)->toBe('resources'));

    // A szülő folyamat (a worker) él: ugyanabban a folyamatban a legitim fájl rendben beolvasható.
    expect(isoRows((new IsolatedXlsxRowReader(isoLegit(), 'Kontaktok', sys_get_temp_dir(), 30))->rows()))->toHaveCount(5);
});

it('B2(r10) · a hatalmas egyetlen cella (libxml expand(), a memory_limit-en kívül) elutasítva — Linuxon a címtér-limit, máshol az időkeret', function () {
    // 2 M csomópontos egyetlen <is> — az openspout expand()-je a libxml saját memóriájában építi fel.
    $cell = isoBook('<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is>'.str_repeat('<r><t>x</t></r>', 700_000).'</is></c></row></sheetData></worksheet>');

    if (IsolatedXlsxRowReader::addressSpaceLimitSupported()) {
        config()->set('audience.import.xlsx_child_address_space_mb', 192);

        expect(fn () => isoRows((new IsolatedXlsxRowReader($cell, null, sys_get_temp_dir(), 60))->rows()))
            ->toThrow(fn (ImportLimitExceeded $e) => expect($e->reason)->toBe('resources'));
    } else {
        // macOS (dev): nincs címtér-limit — a falióra-keret állítja meg (és a gyerek megölve).
        $started = microtime(true);

        expect(fn () => isoRows((new IsolatedXlsxRowReader($cell, null, sys_get_temp_dir(), 1))->rows()))
            ->toThrow(fn (ImportLimitExceeded $e) => expect($e->reason)->toBeIn(['timeout', 'resources']))
            ->and(microtime(true) - $started)->toBeLessThan(5);
    }
});

it('D220 · időtúllépés → elutasítás, a gyerek megölve (nem várunk rá)', function () {
    IsolatedXlsxRowReader::delayChildForTests(10);
    $started = microtime(true);

    expect(fn () => isoRows((new IsolatedXlsxRowReader(isoLegit(), 'Kontaktok', sys_get_temp_dir(), 1))->rows()))
        ->toThrow(fn (ImportLimitExceeded $e) => expect($e->reason)->toBe('timeout'))
        ->and(microtime(true) - $started)->toBeLessThan(4);
});

it('D220 · a korai leállás (a felmérés mintavétele) a gyereket is leállítja', function () {
    $path = isoLegit();
    $reader = new IsolatedXlsxRowReader($path, 'Kontaktok', sys_get_temp_dir(), 30);

    foreach ($reader->rows() as $cells) {
        break;
    }

    expect($reader->lastChildPid())->not->toBeNull()
        ->and(posix_kill((int) $reader->lastChildPid(), 0))->toBeFalse();
});

it('D220 · a gyerek-olvasó a sor- és oszlop-plafont ugyanúgy érvényesíti (ugyanaz az ok)', function () {
    config()->set('audience.import.max_columns', 3);

    expect(fn () => isoRows((new IsolatedXlsxRowReader(isoLegit(), 'Kontaktok', sys_get_temp_dir(), 30))->rows()))
        ->toThrow(fn (ImportLimitExceeded $e) => expect($e->reason)->toBe('columns'));
});

it('S1(r10) · legfeljebb 255 munkalap — a 256. formátum-hiba a guardban', function () {
    $sheet = '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>email</t></is></c></row></sheetData></worksheet>';

    expect(fn () => app(ImportFileInspector::class)->guard(isoBook($sheet, 255), 'xlsx'))->not->toThrow(ImportLimitExceeded::class);

    expect(fn () => app(ImportFileInspector::class)->guard(isoBook($sheet, 256), 'xlsx'))
        ->toThrow(fn (ImportLimitExceeded $e) => expect($e->reason)->toBe('format'));
});

/* ---------------------------------------------------------------- 11. review-kör: S1, S2, N1, N2 */

/** Sok soros legitim munkafüzet — a gyerek kimenete bőven meghaladja a cső pufferét (64 KB). */
function isoMany(int $rows = 5000): string
{
    $path = isoPath();
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Sok');

    for ($i = 0; $i < $rows; $i++) {
        $writer->addRow(Row::fromValues(['user'.$i.'@pelda.hu', 'Név '.$i, str_repeat('adat ', 10)]));
    }

    $writer->close();

    return $path;
}

it('S1(r11) · a gyerek MINIMÁLIS környezetet kap: nincs APP_KEY, DB_*, REDIS_* és egyéb titok', function () {
    putenv('DB_PASSWORD=titok-a-szuloben');
    putenv('APP_KEY=base64:titok');

    try {
        $probe = (new IsolatedXlsxRowReader(isoLegit(), null, sys_get_temp_dir(), 30))->probeChildForTests();
    } finally {
        putenv('DB_PASSWORD');
        putenv('APP_KEY');
    }

    expect($probe['env'])->toContain('PATH')
        // A shell (PWD, SHLVL, _) és macOS-en az OS (`__CF_USER_TEXT_ENCODING`, szövegkódolás-jelző) maga
        // tesz be változót — titkot egyik sem hordoz.
        ->and(array_values(array_diff($probe['env'], ['PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'TMPDIR', 'TMP', 'TEMP', 'PWD', 'SHLVL', '_', '__CF_USER_TEXT_ENCODING'])))->toBe([])
        ->and($probe['env'])->not->toContain('APP_KEY')
        ->and(preg_grep('/^(DB_|REDIS_|APP_|MAIL_|AWS_)/', $probe['env']))->toBe([]);
});

it('S1(r11) · a szülő nyitott fd-i (socket, fájl) NEM jutnak a gyerekhez — csak 0–2 (Linux)', function () {
    if (! is_dir('/proc/self/fd')) {
        $this->markTestSkipped('A nyitott fd-k listája /proc nélkül (macOS) nem mérhető; a wrapper itt a /dev/fd-n át zár — Linuxon (CI, éles) fut.');
    }

    // A szülőben nyitott fájlok és egy TCP-socket — ezek NEM öröklődhetnek.
    $keep = [];

    for ($i = 0; $i < 5; $i++) {
        $keep[] = fopen(isoPath('.tmp'), 'w');
    }

    $server = stream_socket_server('tcp://127.0.0.1:0');
    $client = stream_socket_client('tcp://'.stream_socket_get_name($server, false));

    $probe = (new IsolatedXlsxRowReader(isoLegit(), null, sys_get_temp_dir(), 30))->probeChildForTests();

    // A 0–2 a csövek. A gyerek SAJÁT fd-i maradhatnak: a futó szkript (a PHP nyitva tartja) és a
    // `/proc/self/fd` listázásának könyvtár-fd-je (a readlink idejére már zárva: üres cél). Örökölt nem.
    $script = base_path('app/Modules/Audience/Resources/xlsx-reader-child.php');
    $inherited = array_values(array_filter($probe['fds'], static function (string $fd) use ($script): bool {
        [$number, $target] = array_map('trim', explode('->', $fd, 2) + [1 => '']);

        return (int) $number > 2 && $target !== '' && $target !== $script && ! preg_match('#^/proc/\d+/fd$#', $target);
    }));

    expect($inherited)->toBe([])
        // Kifejezetten: sem socket, sem a szülő ideiglenes fájljai.
        ->and(preg_grep('#socket:|vm-iso-.*\.tmp#', $probe['fds']))->toBe([]);

    fclose($client);
    fclose($server);
    array_map('fclose', $keep);
});

it('S2(r11) · árva gyerek: ha a szülő eltűnik (a cső zárva), a gyerek az első sikertelen írásnál kilép (exit 4)', function () {
    $reader = new IsolatedXlsxRowReader(isoMany(), 'Sok', sys_get_temp_dir(), 30);
    $command = (new ReflectionMethod($reader, 'command'))->invoke($reader);
    // Ugyanaz a MINIMÁLIS környezet, amivel az olvasó indítja (polish N4).
    $environment = (new ReflectionMethod(IsolatedXlsxRowReader::class, 'childEnvironment'))->invoke(null);

    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    fclose($pipes[0]);
    // A „szülő" eltűnik: a kimenetet egyáltalán nem olvassa, a cső olvasó végét lezárja.
    fclose($pipes[1]);

    $started = microtime(true);

    do {
        $status = proc_get_status($process);
        usleep(50_000);
    } while ($status['running'] && microtime(true) - $started < 15);

    fclose($pipes[2]);
    proc_close($process);

    expect($status['running'])->toBeFalse()
        ->and($status['exitcode'])->toBe(4)
        ->and(microtime(true) - $started)->toBeLessThan(15);
});

it('N1(r11) · a hívó korai leállása (break) utáni SAJÁT kill nem „rendellenes kilépés" a naplóban', function () {
    Log::spy();

    foreach ((new IsolatedXlsxRowReader(isoMany(), 'Sok', sys_get_temp_dir(), 30))->rows() as $cells) {
        break;
    }

    Log::shouldNotHaveReceived('info', [Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'rendellenesen')), Mockery::any()]);
});

it('N2(r11) · újsor nélküli, 4 MB-nál hosszabb gyerek-kimenet erőforrás-hiba (a puffer korlátos)', function () {
    // Hamis „php": minden argumentumot figyelmen kívül hagy, és 5 MB-ot ír újsor nélkül.
    $fake = isoPath('.sh');
    file_put_contents($fake, "#!/bin/sh\nhead -c 5000000 /dev/zero | tr '\\000' x\nsleep 5\n");
    chmod($fake, 0755);
    config()->set('audience.import.xlsx_child_php', $fake);

    expect(fn () => isoRows((new IsolatedXlsxRowReader(isoLegit(), null, sys_get_temp_dir(), 30))->rows()))
        ->toThrow(fn (ImportLimitExceeded $e) => expect($e->reason)->toBe('resources'));
});

it('polish · az NDJSON-sorplafon a konfigurációból jön (max_columns × max_cell × 6), legalább 4 MB', function () {
    $max = new ReflectionMethod(IsolatedXlsxRowReader::class, 'maxLineBytes');

    config()->set('audience.import.max_columns', 100);
    config()->set('audience.import.max_cell_length', 2000);
    expect($max->invoke(null))->toBe(4 * 1024 * 1024);

    config()->set('audience.import.max_columns', 500);
    config()->set('audience.import.max_cell_length', 10_000);
    expect($max->invoke(null))->toBe(500 * 10_000 * 6);
});
