<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use App\Modules\Validation\Services\EmailSyntax;
use ZipArchive;

/**
 * Az import-fájl FELMÉRÉSE feltöltéskor: formátum-részletek, fejléc, minta, sorszám.
 *
 * Minden mérés STREAMELT — egy 300 000 soros fájl felmérése sem tölti be a fájlt a
 * memóriába. A határok (sor, oszlop, zip-bomba) is itt dőlnek el, MIELŐTT bármi a
 * feldolgozásig jutna.
 */
final class ImportFileInspector
{
    private const DELIMITERS = [',', ';', "\t", '|'];

    public function __construct(private readonly EmailSyntax $syntax) {}

    /**
     * OLCSÓ ŐRÖK a KÉRÉSBEN (D71): zip-szerkezet + valós bájt- és csomópont-számlálás streamen. Az
     * openspout NEM fut; a teljes felmérés a háttérjobban (`inspect()`).
     *
     * @throws ImportLimitExceeded
     */
    public function guard(string $path, string $format): void
    {
        $this->deadline = microtime(true) + (int) config('audience.import.inspect_budget_seconds');

        if ($format === 'xlsx') {
            $this->assertSafeZip($path);
        }
    }

    /**
     * @return array{
     *     format: string, delimiter: string, encoding: string, sheets: list<string>,
     *     sheet: string|null, hasHeader: bool, columns: list<string>, width: int,
     *     sample: list<list<string>>, records: int, rows: int
     * }
     *
     * @throws ImportLimitExceeded
     */
    public function inspect(string $path, string $format, ?string $sheet = null, ?int $budget = null): array
    {
        $this->deadline = microtime(true) + ($budget ?? (int) config('audience.import.inspect_budget_seconds'));

        return $format === 'xlsx' ? $this->xlsx($path, $sheet) : $this->csv($path);
    }

    /** A felmérés időkerete (a kérésen belül fut — lásd a konfig `inspect_budget_seconds`). */
    private float $deadline = 0.0;

    private function checkBudget(): void
    {
        if (microtime(true) > $this->deadline) {
            throw new ImportLimitExceeded('timeout');
        }
    }

    /** @return array<string, mixed> */
    private function csv(string $path): array
    {
        $delimiter = $this->detectDelimiter($path);
        $encoding = $this->detectEncoding($path);

        return $this->profile(
            new CsvRowReader($path, $delimiter, $encoding),
            format: 'csv',
            delimiter: $delimiter,
            encoding: $encoding,
            sheets: [],
            sheet: null,
        );
    }

    /** @return array<string, mixed> */
    private function xlsx(string $path, ?string $sheet): array
    {
        // A zip-ellenőrzés EGYBEN gyűjti a munkalapok csomópont- és sorstatisztikáját: a
        // felmérés a teljes XML-t SOHA nem a (lassú) openspout-tal járja végig — az a háttérjob
        // dolga. Egy `<row>` nélküli, csomóponttal teli `sheetData` (a security-review 9,9 MB-os,
        // 200 MB-ra nyíló fájlja: 50 s) ezen a ponton, másodpercek alatt elbukik.
        $stats = $this->assertSafeZip($path);

        $sheets = $this->sheetNames($path);

        if ($sheets === []) {
            throw new ImportLimitExceeded('format');
        }

        $sheet ??= $sheets[0];

        if (! in_array($sheet, $sheets, true)) {
            throw new ImportLimitExceeded('sheet');
        }

        $file = $this->sheetFile($path, $sheet);
        // A munkalap-fájl statisztikája nélkül (szokatlan nevű bejegyzés) nincs mit felmérni: a
        // nem-munkalap bejegyzés a szigorú meta-plafont kapta, és ide sosem jut el sorokkal.
        if (! isset($stats[strtolower($file)]) && ! isset($stats[$file])) {
            throw new ImportLimitExceeded('format');
        }

        $records = (int) (($stats[$file] ?? $stats[strtolower($file)])['rows'] ?? 0);

        return $this->profile(
            new XlsxRowReader($path, $sheet),
            format: 'xlsx',
            delimiter: ',',
            encoding: 'UTF-8',
            sheets: $sheets,
            sheet: $sheet,
            knownRecords: $records,
        );
    }

    /**
     * @param  list<string>  $sheets
     * @return array<string, mixed>
     */
    private function profile(
        ImportRowReader $reader,
        string $format,
        string $delimiter,
        string $encoding,
        array $sheets,
        ?string $sheet,
        ?int $knownRecords = null,
    ): array {
        $first = null;
        $sample = [];
        $count = 0;
        $width = 0;

        $rows = $reader->rows();

        foreach ($rows as $cells) {
            if (($count & 1023) === 0) {
                $this->checkBudget();
            }

            // XLSX: a sorszámot a zip-menet adta; a mintához elég az első néhány sor (a
            // generátor lezárásával az olvasó is leáll, nem megy végig a fájlon).
            if ($knownRecords !== null && $count >= 6) {
                break;
            }

            $count++;
            $width = max($width, count($cells));

            if ($count === 1) {
                $first = $cells;

                continue;
            }

            if (count($sample) < 5) {
                $sample[] = $cells;
            }
        }

        unset($rows);

        if ($knownRecords !== null) {
            $count = $knownRecords;
        }

        $first ??= [];
        $hasHeader = $first !== [] && ! $this->containsEmail($first);

        if (! $hasHeader && $first !== []) {
            // Fejléc nélkül az első sor ADAT: a mintába és a számba is beleszámít.
            array_unshift($sample, $first);
            $sample = array_slice($sample, 0, 5);
        }

        return [
            'format' => $format,
            'delimiter' => $delimiter,
            'encoding' => $encoding,
            'sheets' => $sheets,
            'sheet' => $sheet,
            'hasHeader' => $hasHeader,
            'columns' => $hasHeader ? array_values($first) : [],
            'width' => $width,
            'sample' => $sample,
            // A fájl nem üres rekordjai (a fejléccel együtt): a `rows` ebből számolódik újra,
            // ha a felhasználó átállítja a „van fejléc" jelölőt.
            'records' => $count,
            'rows' => $hasHeader ? max(0, $count - 1) : $count,
        ];
    }

    /** @param list<string> $cells */
    private function containsEmail(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (str_contains($cell, '@') && $this->syntax->valid($cell)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ELVÁLASZTÓ az első sorokból: az nyer, amelyik a legtöbb sorban ugyanannyi (>1) mezőre
     * bontja a szöveget. Az idézőjelet a `str_getcsv` kezeli, tehát a mezőn belüli
     * elválasztó nem téveszt meg.
     */
    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $lines = [];

        while ($handle !== false && count($lines) < 20 && ($line = fgets($handle, 65536)) !== false) {
            if (trim($line) !== '') {
                $lines[] = rtrim($line, "\r\n");
            }
        }

        if ($handle !== false) {
            fclose($handle);
        }

        if ($lines !== []) {
            $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);
        }

        $best = ',';
        $bestScore = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $counts = array_map(static fn (string $line): int => count(str_getcsv($line, $delimiter, '"', '')), $lines);
            $frequency = array_count_values($counts);
            arsort($frequency);
            $modal = array_key_first($frequency);

            if ($modal === null || $modal < 2) {
                continue;
            }

            $score = $frequency[$modal] * 1000 + $modal;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $delimiter;
            }
        }

        return $best;
    }

    /**
     * KÓDOLÁS: érvényes UTF-8 (BOM-mal vagy anélkül) → `UTF-8`, különben `Windows-1250`.
     * Soronként ellenőrzünk, tehát a memória állandó; a többbájtos karaktert nem vághatja el
     * a sorhatár (az újsor ASCII).
     */
    private function detectEncoding(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return 'UTF-8';
        }

        try {
            $cap = (int) config('audience.import.max_line_bytes');

            // UTF-16 BOM: a fájl nem ASCII-kompatibilis, a sorok NUL-bájtokkal tele — az olvasó
            // UTF-8-nak venné, és a NUL a mintából az adatbázisba jutna. Kódolási hiba.
            $bom = (string) fread($handle, 2);
            rewind($handle);

            if ($bom === "\xFF\xFE" || $bom === "\xFE\xFF") {
                throw new ImportLimitExceeded('encoding');
            }

            while (($line = fgets($handle, $cap + 1)) !== false) {
                // NUL-bájt sehol a CSV-ben (UTF-16 BOM nélkül, vagy hibás export): a Postgres
                // szövegben nem tárolja, és a hibaüzenete a címeket tartalmazná.
                if (str_contains($line, "\0")) {
                    throw new ImportLimitExceeded('encoding');
                }

                // Újsor nélkül a határig teli sor = a fizikai sor túl hosszú.
                if (strlen($line) > $cap || (strlen($line) === $cap && ! str_ends_with($line, "\n"))) {
                    throw new ImportLimitExceeded('line');
                }

                $this->checkBudget();

                if (! mb_check_encoding($line, 'UTF-8')) {
                    return 'Windows-1250';
                }
            }
        } finally {
            fclose($handle);
        }

        return 'UTF-8';
    }

    /**
     * ZIP-BOMBA ŐR — a kitömörített méretet a zip KÖZPONTI JEGYZÉKÉBŐL olvassuk, nulla
     * bájt kitömörítése nélkül. Három határ: bejegyzések száma, összméret, és a tömörítési
     * arány (a nullákból álló 1 GB néhány KB-ra tömörödik — az arány ezt fogja meg akkor is,
     * ha az összméret belefér).
     */
    private function assertSafeZip(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportLimitExceeded('format');
        }

        try {
            if ($zip->numFiles > (int) config('audience.import.xlsx_max_entries')) {
                throw new ImportLimitExceeded('zip');
            }

            $total = 0;
            $maxRatio = (int) config('audience.import.xlsx_max_ratio');
            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false) {
                    throw new ImportLimitExceeded('format');
                }

                $names[] = $stat['name'];
                $total += (int) $stat['size'];

                if ($total > (int) config('audience.import.xlsx_max_uncompressed_bytes')) {
                    throw new ImportLimitExceeded('zip');
                }

                if ($stat['size'] > 1024 * 1024 && $stat['comp_size'] > 0 && $maxRatio < $stat['size'] / $stat['comp_size']) {
                    throw new ImportLimitExceeded('zip');
                }
            }

            if (! in_array('[Content_Types].xml', $names, true) || ! in_array('xl/workbook.xml', $names, true)) {
                throw new ImportLimitExceeded('format');
            }

            return $this->countRealBytes($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * A VALÓS kitömörített bájtok számolása — a központi jegyzék mérete HAMISÍTHATÓ (a security-
     * review 205 KB-os fájlja 1000 bájtot hirdetett, és 200 MB-ot töltött ki ~55 mp CPU-n).
     * Streamen olvasunk, és megszakítjuk, ha egy bejegyzés meghaladja a deklarált méretét, vagy az
     * összeg a plafont, vagy az idő a keretet. A leállás a plafonnál történik, nem a végén.
     */
    private function countRealBytes(ZipArchive $zip): array
    {
        $ceiling = (int) config('audience.import.xlsx_max_uncompressed_bytes');
        $maxNodes = (int) config('audience.import.xlsx_max_nodes');
        $beforeData = (int) config('audience.import.xlsx_max_nodes_before_data');
        $total = 0;
        $stats = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $declared = (int) $stat['size'];
            // INDEX alapján, nem név alapján: egy ismétlődő nevű bejegyzés névvel a MÁSIKAT adná.
            $stream = $zip->getStreamIndex($i);

            if ($stream === false) {
                throw new ImportLimitExceeded('format');
            }

            // NÉV-FÜGGETLEN számlálás: az XLSX bejegyzés-neve a `rels` Target-jéből jön, tehát
            // `.bin`/`.XML`/bármi lehet — a csomópontot MINDEN bejegyzésben számoljuk, a név csak a
            // plafont választja (a nem-munkalap bejegyzés a szigorú meta-plafont kapja).
            $lowerName = strtolower($stat['name']);
            $isSheet = str_starts_with($lowerName, 'xl/worksheets/') && str_ends_with($lowerName, '.xml');
            $partCap = str_contains($lowerName, 'sharedstrings')
                ? (int) config('audience.import.xlsx_max_part_nodes')
                : (int) config('audience.import.xlsx_max_meta_nodes');
            $entry = 0;
            $nodes = 0;
            $rows = 0;
            $sixthRowNodes = null;
            $carry = '';

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 65536);

                    if ($chunk === false) {
                        throw new ImportLimitExceeded('format');
                    }

                    $entry += strlen($chunk);
                    $total += strlen($chunk);

                    if ($entry > $declared || $total > $ceiling) {
                        throw new ImportLimitExceeded('zip');
                    }

                    if ($isSheet) {
                        // CSOMÓPONT-SZÁMLÁLÁS: a `<` jelek száma olcsó (másodperc törtrésze 200 MB-on),
                        // és közelíti, mennyit dolgozna az XML-olvasó. Plafon az egészre ÉS az első
                        // adatsor ELŐTTI részre: a `<row>` nélküli áradat itt bukik el.
                        $nodes += substr_count($chunk, '<');
                        $buffer = $carry.$chunk;
                        $rows += substr_count($buffer, '<row ') + substr_count($buffer, '<row>');
                        $carry = substr($buffer, -4);

                        // A mintát az első HAT sorból olvassa az openspout, és közben végigmegy a köztes
                        // csomópontokon: ha a hatodik sorig (vagy, ha kevesebb sor van, a fájl végéig)
                        // több csomópont van a plafonnál, a felmérés időkerete megkerülhető lenne.
                        if ($rows >= 6 && $sixthRowNodes === null) {
                            $sixthRowNodes = $nodes;
                        }

                        if (
                            $nodes > $maxNodes
                            || ($rows < 6 && $nodes > $beforeData)
                            || ($sixthRowNodes !== null && $sixthRowNodes > $beforeData)
                        ) {
                            throw new ImportLimitExceeded('zip');
                        }
                    }

                    if (! $isSheet) {
                        $nodes += substr_count($chunk, '<');

                        if ($nodes > $partCap) {
                            throw new ImportLimitExceeded('zip');
                        }
                    }

                    $this->checkBudget();
                }
            } finally {
                fclose($stream);
            }

            if ($isSheet) {
                $stats[$stat['name']] = ['nodes' => $nodes, 'rows' => $rows];
            }
        }

        return $stats;
    }

    /**
     * A munkalap fájlja (`xl/worksheets/sheetN.xml`) a neve alapján: a `workbook.xml` `r:id`-ja és
     * a `workbook.xml.rels` célja. A két fájl kicsi — olvasásuk korlátos.
     */
    private function sheetFile(string $path, string $name): string
    {
        $rid = null;

        foreach ($this->workbookSheets($path) as $sheet) {
            if ($sheet['name'] === $name) {
                $rid = $sheet['rid'];
            }
        }

        $zip = new ZipArchive;

        if ($rid === null || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportLimitExceeded('format');
        }

        try {
            $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels', 1_048_576);
        } finally {
            $zip->close();
        }

        $relationships = @simplexml_load_string($rels, options: LIBXML_NONET);

        if ($relationships === false) {
            throw new ImportLimitExceeded('format');
        }

        foreach ($relationships->Relationship ?? [] as $relationship) {
            if ((string) $relationship['Id'] === $rid) {
                $target = ltrim((string) $relationship['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        throw new ImportLimitExceeded('format');
    }

    /**
     * A munkalapok nevei a `workbook.xml`-ből (simplexml, korlátos olvasás) — NEM az openspoutból:
     * az újabb XML-menetet indítana a (drága) sharedStrings fölött, és a költséget megduplázná.
     *
     * @return list<string>
     */
    private function sheetNames(string $path): array
    {
        $names = [];

        foreach ($this->workbookSheets($path) as $sheet) {
            $names[] = $sheet['name'];
        }

        return $names;
    }

    /** @return list<array{name: string, rid: string}> */
    private function workbookSheets(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportLimitExceeded('format');
        }

        try {
            $workbook = (string) $zip->getFromName('xl/workbook.xml', 1_048_576);
        } finally {
            $zip->close();
        }

        $book = @simplexml_load_string($workbook, options: LIBXML_NONET);

        if ($book === false) {
            throw new ImportLimitExceeded('format');
        }

        $sheets = [];

        foreach ($book->sheets->sheet ?? [] as $candidate) {
            $sheets[] = [
                'name' => (string) $candidate['name'],
                'rid' => (string) ($candidate->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? ''),
            ];
        }

        return $sheets;
    }
}
