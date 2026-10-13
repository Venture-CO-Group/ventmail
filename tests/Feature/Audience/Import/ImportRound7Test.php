<?php

declare(strict_types=1);

use App\Modules\Audience\Services\Import\ImportFileInspector;
use App\Modules\Audience\Services\Import\ImportLimitExceeded;
use App\Modules\Audience\Services\Import\XlsxStructure;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
 | v1.1 · 6. közönség-import — modul 5, 7. review-kör (m5-review7.md): SZERKEZETI őr.
 |
 | A bájt-/regex-alapú DTD- és kódolás-szűrés ismétlődő megkerülése után a döntő ellenőrzés ugyanaz
 | a parser (libxml XMLReader), amelyet az openspout is használ, és a hivatkozás-feloldás az openspout
 | szemantikáját követi (bármely mélységű, bármely előtagú `Relationship`).
 */

const R7_SHARED = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings';

/** @var list<string> */
$GLOBALS['r7Files'] = [];

function r7Zip(array $entries): string
{
    $path = sys_get_temp_dir().'/vm-r7-'.uniqid().'.xlsx';
    $GLOBALS['r7Files'][] = $path;
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);

    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();

    return $path;
}

/** Minimális, de openspout által olvasható munkafüzet; a `$rels` a teljes workbook.xml.rels tartalma. */
function r7Book(?string $rels = null, array $extra = []): array
{
    return array_merge([
        '[Content_Types].xml' => '<Types/>',
        'xl/workbook.xml' => '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $rels ?? '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>email</t></is></c></row></sheetData></worksheet>',
    ], $extra);
}

function r7Rejects(string $path, string $reason = 'format'): void
{
    expect(fn () => app(ImportFileInspector::class)->guard($path, 'xlsx'))
        ->toThrow(fn (ImportLimitExceeded $e) => expect($e->reason)->toBe($reason));
}

afterEach(function () {
    foreach ($GLOBALS['r7Files'] as $path) {
        @unlink($path);
    }

    $GLOBALS['r7Files'] = [];
});

/* ---------------------------------------------------------------- B1: Relationship bármely alakban */

it('B1(r7) · az előtagos, a beágyazott és a gyökér-Relationship is feloldódik — bináris helyre nem mutathat', function (string $rels) {
    // A „bináris" helyen egy olyan rész, amelyet a szöveg-ellenőrzés soha nem látott.
    r7Rejects(r7Zip(r7Book($rels, ['xl/media/s.xml' => '<sst><si><t>x</t></si></sst>'])));
})->with([
    'előtagos (p:Relationship)' => ['<p:Relationships xmlns:p="http://schemas.openxmlformats.org/package/2006/relationships"><p:Relationship Id="rId1" Target="worksheets/sheet1.xml"/><p:Relationship Id="rId2" Type="'.R7_SHARED.'" Target="media/s.xml"/></p:Relationships>'],
    'beágyazott' => ['<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><x><Relationship Id="rId2" Type="'.R7_SHARED.'" Target="media/s.xml"/></x></Relationships>'],
    'gyökér-Relationship' => ['<Relationship Id="rId2" Type="'.R7_SHARED.'" Target="media/s.xml"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationship>'],
    'abszolút /xl/ útvonal, nagybetűvel' => ['<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="'.R7_SHARED.'" Target="/xl/MEDIA/s.xml"/></Relationships>'],
]);

it('B1(r7) · a feloldás az openspout szabálya szerint történik (relatív → xl/, abszolút /xl/ marad)', function () {
    $rels = XlsxStructure::relationshipsFrom('<p:Relationships xmlns:p="urn:x"><a><p:Relationship Id="rId9" Type="T" Target="/xl/styles.xml"/></a></p:Relationships>');

    expect($rels)->toBe([['id' => 'rId9', 'type' => 'T', 'target' => '/xl/styles.xml', 'external' => false]])
        ->and(XlsxStructure::partPathFor('styles.xml'))->toBe('xl/styles.xml')
        ->and(XlsxStructure::partPathFor('/xl/styles.xml'))->toBe('xl/styles.xml')
        ->and(XlsxStructure::sheetPathFor('worksheets/sheet1.xml'))->toBe('xl/worksheets/sheet1.xml')
        ->and(XlsxStructure::sheetPathFor('/xl/worksheets/sheet1.xml'))->toBe('xl/worksheets/sheet1.xml');
});

it('B1(r7) · hiányzó bejegyzésre mutató (belső) hivatkozás formátum-hiba', function () {
    r7Rejects(r7Zip(r7Book('<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="'.R7_SHARED.'" Target="sharedStrings.xml"/></Relationships>')));
});

it('B1(r7) · a külső (TargetMode="External") hivatkozás nem bejegyzés — nem kell léteznie', function () {
    $path = r7Zip(r7Book('<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId3" Type="hyperlink" Target="https://pelda.hu/x" TargetMode="External"/></Relationships>'));

    expect(fn () => app(ImportFileInspector::class)->guard($path, 'xlsx'))->not->toThrow(ImportLimitExceeded::class);
});

/* ---------------------------------------------------------------- S1: kódolás-független szerkezeti őr */

it('S1(r7) · az 512 bájton túl nyúló UTF-7 deklaráció elutasítva', function () {
    $xml = '<?xml version="1.0"'.str_repeat(' ', 600).'encoding="UTF-7"?>+ADw-!DOCTYPE x +AFs-+ADw-!ENTITY a +ACI-pwn+ACI-+AD4-+AF0-+AD4-+ADw-styleSheet/+AD4-';

    r7Rejects(r7Zip(r7Book(null, ['xl/styles.xml' => $xml])));
});

it('S1(r7) · a szerkezeti szűrő a parser szemével lát: DOCTYPE UTF-16-ban, EBCDIC-ben is', function (string $bytes) {
    expect(fn () => XlsxStructure::assertWellFormedPart('data://text/plain;base64,'.base64_encode($bytes), full: true))
        ->toThrow(fn (ImportLimitExceeded $e) => expect($e->reason)->toBe('format'));
})->with([
    'UTF-16LE DOCTYPE' => ["\xFF\xFE".mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY a "b">]><x>&a;</x>', 'UTF-16LE', 'UTF-8')],
    'UTF-8 DOCTYPE' => ['<?xml version="1.0"?><!DOCTYPE x SYSTEM "x.dtd"><x/>'],
    'nem deklarált entitás-hivatkozás' => ['<styleSheet>&foo;</styleSheet>'],
    'csonka XML' => ['<styleSheet><a>'],
]);

it('S1(r7) · az entitás-hivatkozás a guardon is elbukik (a bájt-szűrő nem látja, a parser igen)', function () {
    r7Rejects(r7Zip(r7Book(null, ['xl/styles.xml' => '<styleSheet>&foo;</styleSheet>'])));
});

it('S1(r7) · a munkalapnál a prológus + gyökér ellenőrzött (DOCTYPE ott sem lehet)', function () {
    $sheet = '<?xml version="1.0"?><!DOCTYPE worksheet [<!ENTITY a "b">]><worksheet><sheetData/></worksheet>';

    r7Rejects(r7Zip(r7Book(null, ['xl/worksheets/sheet1.xml' => $sheet])));
});

/* ---------------------------------------------------------------- N1, N2: openspout-paritás, bináris lista */

it('N1(r7) · a munkalapok neve és fájlja az openspouttal azonos módon (x: előtag, r:id) oldódik fel', function () {
    $path = r7Zip(r7Book(
        '<Relationships><Relationship Id="rId1" Target="/xl/worksheets/sheet1.xml"/></Relationships>',
        ['xl/workbook.xml' => '<x:workbook xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><x:sheets><x:sheet name="Előtagos" sheetId="1" r:id="rId1"/></x:sheets></x:workbook>'],
    ));

    $profile = app(ImportFileInspector::class)->inspect($path, 'xlsx');

    expect($profile['sheets'])->toBe(['Előtagos'])
        ->and($profile['sheet'])->toBe('Előtagos');
});

it('N2(r7) · a valós Excel-szerű fájl (téma, nyomtatóbeállítás, Power Pivot modell, miniatűr) átmegy és felmérhető', function () {
    $source = sys_get_temp_dir().'/vm-r7-real-'.uniqid().'.xlsx';
    $GLOBALS['r7Files'][] = $source;

    $writer = new Writer;
    $writer->openToFile($source);
    $writer->getCurrentSheet()->setName('Kontaktok');
    $writer->addRow(Row::fromValues(['email', 'név']));
    $writer->addRow(Row::fromValues(['anna@pelda.hu', 'Anna']));
    $writer->addRow(Row::fromValues(['bela@pelda.hu', 'Béla']));
    $writer->close();

    // A valós Excel bináris részei — a munkafüzet nem hivatkozik rájuk XML-ként.
    $zip = new ZipArchive;
    $zip->open($source);
    $zip->addFromString('xl/printerSettings/printerSettings1.bin', mb_convert_encoding('HP LaserJet', 'UTF-16LE', 'UTF-8').str_repeat("\0", 64));
    $zip->addFromString('xl/model/item.data', "\0\x01\x02".str_repeat("\0", 64));
    $zip->addFromString('docProps/thumbnail.wmf', "\xD7\xCD\xC6\x9A".str_repeat("\0", 32));
    $zip->close();

    expect(fn () => app(ImportFileInspector::class)->guard($source, 'xlsx'))->not->toThrow(ImportLimitExceeded::class);

    $profile = app(ImportFileInspector::class)->inspect($source, 'xlsx');

    expect($profile['sheets'])->toBe(['Kontaktok'])
        ->and($profile['records'])->toBeGreaterThanOrEqual(2);
});
