<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use App\Modules\Validation\Services\EmailSyntax;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
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
     * @return array{
     *     format: string, delimiter: string, encoding: string, sheets: list<string>,
     *     sheet: string|null, hasHeader: bool, columns: list<string>, width: int,
     *     sample: list<list<string>>, records: int, rows: int
     * }
     *
     * @throws ImportLimitExceeded
     */
    public function inspect(string $path, string $format, ?string $sheet = null): array
    {
        return $format === 'xlsx' ? $this->xlsx($path, $sheet) : $this->csv($path);
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
        $this->assertSafeZip($path);

        $sheets = $this->sheetNames($path);

        if ($sheets === []) {
            throw new ImportLimitExceeded('format');
        }

        $sheet ??= $sheets[0];

        if (! in_array($sheet, $sheets, true)) {
            throw new ImportLimitExceeded('sheet');
        }

        return $this->profile(
            new XlsxRowReader($path, $sheet),
            format: 'xlsx',
            delimiter: ',',
            encoding: 'UTF-8',
            sheets: $sheets,
            sheet: $sheet,
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
    ): array {
        $first = null;
        $sample = [];
        $count = 0;
        $width = 0;

        foreach ($reader->rows() as $cells) {
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
            while (($line = fgets($handle)) !== false) {
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
    private function assertSafeZip(string $path): void
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
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    private function sheetNames(string $path): array
    {
        $reader = new Reader(new Options);
        $reader->open($path);
        $names = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $names[] = $sheet->getName();
            }
        } finally {
            $reader->close();
        }

        return $names;
    }
}
