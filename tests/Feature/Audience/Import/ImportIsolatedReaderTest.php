<?php

declare(strict_types=1);

use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Services\Import\ImportFileInspector;
use App\Modules\Audience\Services\Import\ImportLimitExceeded;
use App\Modules\Audience\Services\Import\ImportUploads;
use App\Modules\Audience\Services\Import\IsolatedXlsxRowReader;
use App\Modules\Audience\Services\Import\XlsxRowReader;
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
