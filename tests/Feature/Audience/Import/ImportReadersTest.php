<?php

declare(strict_types=1);

use App\Modules\Audience\Services\Import\ColumnSuggester;
use App\Modules\Audience\Services\Import\CsvRowReader;
use App\Modules\Audience\Services\Import\ImportFileInspector;
use App\Modules\Audience\Services\Import\ImportLimitExceeded;
use App\Modules\Audience\Services\Import\XlsxRowReader;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/*
 | v1.1 · 6. közönség-import — a FÁJLOLVASÁS réteg.
 |
 | Mit kell tudnia: CSV automatikus elválasztó- és kódolásfelismeréssel (UTF-8 BOM-mal és
 | anélkül, Windows-1250), XLSX munkalap-választással — mindkettő STREAMELVE, és mindkettő
 | határok között (sor, oszlop, cellahossz, zip-bomba).
 */

function importTmp(string $suffix, string $contents = ''): string
{
    $path = sys_get_temp_dir().'/vm-import-'.bin2hex(random_bytes(6)).$suffix;
    file_put_contents($path, $contents);

    return $path;
}

/** @return list<list<string>> */
function readAll(CsvRowReader|XlsxRowReader $reader): array
{
    return array_values(iterator_to_array($reader->rows(), false));
}

function makeXlsx(array $sheets): string
{
    $path = importTmp('.xlsx');
    $writer = new XlsxWriter;
    $writer->openToFile($path);

    $first = true;

    foreach ($sheets as $name => $rows) {
        $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);
        $first = false;

        foreach ($rows as $row) {
            // A dátum-cella csak DÁTUM-FORMÁTUMMAL olvasható vissza dátumként (mint az Excelben).
            $writer->addRow(new Row(array_map(
                static fn ($value) => Cell::fromValue($value, $value instanceof DateTimeInterface ? (new Style)->withFormat('yyyy-mm-dd') : null),
                $row,
            )));
        }
    }

    $writer->close();

    return $path;
}

it('a CSV elválasztóját az első sorokból ismeri fel', function (string $delimiter) {
    $csv = implode("\n", [
        implode($delimiter, ['email', 'name', 'city']),
        implode($delimiter, ['a@pelda.hu', 'Anna', 'Győr']),
        implode($delimiter, ['b@pelda.hu', 'Béla', 'Pécs']),
    ])."\n";

    $profile = app(ImportFileInspector::class)->inspect(importTmp('.csv', $csv), 'csv');

    expect($profile['delimiter'])->toBe($delimiter)
        ->and($profile['encoding'])->toBe('UTF-8')
        ->and($profile['columns'])->toBe(['email', 'name', 'city'])
        ->and($profile['hasHeader'])->toBeTrue()
        ->and($profile['rows'])->toBe(2);
})->with([',', ';', "\t", '|']);

it('az idézőjelen belüli elválasztót nem veszi elválasztónak', function () {
    $csv = "email;name\n\"a@pelda.hu\";\"Kiss, Anna; Dr.\"\nb@pelda.hu;Béla\n";

    $path = importTmp('.csv', $csv);
    $profile = app(ImportFileInspector::class)->inspect($path, 'csv');

    expect($profile['delimiter'])->toBe(';');

    $rows = readAll(new CsvRowReader($path, $profile['delimiter'], $profile['encoding']));

    expect($rows[1][1])->toBe('Kiss, Anna; Dr.');
});

it('a UTF-8 BOM-ot leveszi az első cellából', function () {
    $path = importTmp('.csv', "\xEF\xBB\xBFemail,név\na@pelda.hu,Ágnes\n");
    $profile = app(ImportFileInspector::class)->inspect($path, 'csv');

    expect($profile['encoding'])->toBe('UTF-8')
        ->and($profile['columns'])->toBe(['email', 'név']);

    $rows = readAll(new CsvRowReader($path, ',', $profile['encoding']));

    expect($rows[0][0])->toBe('email')->and($rows[1][1])->toBe('Ágnes');
});

it('a Windows-1250 fájlt UTF-8-ra alakítja', function () {
    // A bájtokat KÉZZEL írjuk (a mbstring nem ismeri a Windows-1250-et, az iconv pedig
    // platformfüggő): n=0xE9 é, á=0xE1, É=0xC9, Ő=0xD5, Ű=0xDB.
    $latin = "e-mail;n\xE9v\nkovacs@pelda.hu;Kov\xE1cs \xC9va \xD5 \xDB\n";
    $path = importTmp('.csv', $latin);

    $profile = app(ImportFileInspector::class)->inspect($path, 'csv');

    expect($profile['encoding'])->toBe('Windows-1250')
        ->and($profile['columns'])->toBe(['e-mail', 'név']);

    $rows = readAll(new CsvRowReader($path, $profile['delimiter'], $profile['encoding']));

    expect($rows[1][1])->toBe('Kovács Éva Ő Ű');
});

it('fejléc nélküli fájlt felismer: az első sor már cím', function () {
    $profile = app(ImportFileInspector::class)->inspect(importTmp('.csv', "a@pelda.hu,Anna\nb@pelda.hu,Béla\n"), 'csv');

    expect($profile['hasHeader'])->toBeFalse()
        ->and($profile['rows'])->toBe(2);
});

it('az üres sorokat átugorja, de a sorszámot megtartja', function () {
    $path = importTmp('.csv', "email\n\na@pelda.hu\n\nb@pelda.hu\n");

    $lines = array_keys(iterator_to_array((new CsvRowReader($path, ',', 'UTF-8'))->rows()));

    expect($lines)->toBe([1, 3, 5]);
});

it('az XLSX munkalapjait felsorolja, és a kiválasztottat olvassa', function () {
    $path = makeXlsx([
        'Vendégek' => [['email', 'név'], ['a@pelda.hu', 'Anna']],
        'Másik' => [['mail', 'cég'], ['x@pelda.hu', 'Acme'], ['y@pelda.hu', 'Beta']],
    ]);

    $inspector = app(ImportFileInspector::class);

    $first = $inspector->inspect($path, 'xlsx');

    expect($first['sheets'])->toBe(['Vendégek', 'Másik'])
        ->and($first['sheet'])->toBe('Vendégek')
        ->and($first['columns'])->toBe(['email', 'név'])
        ->and($first['rows'])->toBe(1);

    $second = $inspector->inspect($path, 'xlsx', 'Másik');

    expect($second['sheet'])->toBe('Másik')
        ->and($second['columns'])->toBe(['mail', 'cég'])
        ->and($second['rows'])->toBe(2);

    $rows = readAll(new XlsxRowReader($path, 'Másik'));

    expect($rows)->toBe([['mail', 'cég'], ['x@pelda.hu', 'Acme'], ['y@pelda.hu', 'Beta']]);
});

it('az XLSX számait és dátumait szövegként adja', function () {
    $path = makeXlsx(['S' => [
        ['email', 'telefon', 'szuletes'],
        ['a@pelda.hu', 36301234567, new DateTimeImmutable('1988-04-12')],
    ]]);

    $rows = readAll(new XlsxRowReader($path, null));

    expect($rows[1][1])->toBe('36301234567')->and($rows[1][2])->toBe('1988-04-12');
});

it('a túl sok oszlopot elutasítja', function () {
    config()->set('audience.import.max_columns', 5);

    $path = importTmp('.csv', "a,b,c,d,e,f,g\n1,2,3,4,5,6,7\n");

    expect(fn () => app(ImportFileInspector::class)->inspect($path, 'csv'))
        ->toThrow(ImportLimitExceeded::class);
});

it('a túl sok sort elutasítja', function () {
    config()->set('audience.import.max_rows', 3);

    $path = importTmp('.csv', "email\na@pelda.hu\nb@pelda.hu\nc@pelda.hu\nd@pelda.hu\n");

    expect(fn () => app(ImportFileInspector::class)->inspect($path, 'csv'))
        ->toThrow(ImportLimitExceeded::class);
});

it('a cella hosszát korlátozza', function () {
    config()->set('audience.import.max_cell_length', 10);

    $path = importTmp('.csv', "email,note\na@pelda.hu,".str_repeat('x', 500)."\n");

    $rows = readAll(new CsvRowReader($path, ',', 'UTF-8'));

    expect(mb_strlen($rows[1][1]))->toBe(10);
});

it('az XLSX zip-bombát elutasítja a kitömörítés ELŐTT', function () {
    config()->set('audience.import.xlsx_max_uncompressed_bytes', 5 * 1024 * 1024);

    $path = importTmp('.xlsx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('xl/workbook.xml', '<workbook/>');
    // 20 MB nulla: tömörítve pár tíz kilobájt.
    $zip->addFromString('xl/worksheets/sheet1.xml', str_repeat("\0", 20 * 1024 * 1024));
    $zip->close();

    expect(filesize($path))->toBeLessThan(200 * 1024);

    expect(fn () => app(ImportFileInspector::class)->inspect($path, 'xlsx'))
        ->toThrow(ImportLimitExceeded::class);
});

it('a nem XLSX zip-et (nincs workbook) elutasítja', function () {
    $path = importTmp('.xlsx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('valami.txt', 'szöveg');
    $zip->close();

    expect(fn () => app(ImportFileInspector::class)->inspect($path, 'xlsx'))
        ->toThrow(ImportLimitExceeded::class);
});

/*
 | OSZLOP-JAVASLAT mind a hét nyelv gyakori fejléceire.
 */
it('a fejlécből javasol mezőt mind a hét nyelven', function (string $header, string $target) {
    $suggestion = app(ColumnSuggester::class)->suggest($header);

    expect($suggestion['target'])->toBe($target)
        ->and($suggestion['confidence'])->toBeGreaterThanOrEqual(90);
})->with([
    ['E-mail cím', 'email'], ['email', 'email'], ['E-Mail-Adresse', 'email'], ['Courriel', 'email'],
    ['Adresse e-mail', 'email'], ['Correo electrónico', 'email'], ['Indirizzo email', 'email'],
    ['Электронная почта', 'email'], ['Почта', 'email'],
    ['Vezetéknév', 'last_name'], ['Last name', 'last_name'], ['Nachname', 'last_name'],
    ['Nom de famille', 'last_name'], ['Cognome', 'last_name'], ['Apellidos', 'last_name'], ['Фамилия', 'last_name'],
    ['Keresztnév', 'first_name'], ['First name', 'first_name'], ['Vorname', 'first_name'],
    ['Prénom', 'first_name'], ['Nome', 'first_name'], ['Nombre', 'first_name'], ['Имя', 'first_name'],
    ['Cég neve', 'attr:company'], ['Company', 'attr:company'], ['Unternehmen', 'attr:company'],
    ['Société', 'attr:company'], ['Azienda', 'attr:company'], ['Empresa', 'attr:company'], ['Компания', 'attr:company'],
    ['Telefon', 'attr:phone'], ['Phone', 'attr:phone'], ['Téléphone', 'attr:phone'], ['Teléfono', 'attr:phone'], ['Телефон', 'attr:phone'],
]);

it('az ismeretlen fejlécre nem javasol semmit', function () {
    $suggestion = app(ColumnSuggester::class)->suggest('Törzsvendég-kód');

    expect($suggestion['target'])->toBeNull()->and($suggestion['confidence'])->toBe(0);
});

it('a meglévő egyéni mező kulcsára vagy címkéjére is rátalál', function () {
    $suggestion = app(ColumnSuggester::class)->suggest('Születésnap', [
        ['key' => 'birthday', 'label' => 'Születésnap'],
    ]);

    expect($suggestion['target'])->toBe('attr:birthday');
});
