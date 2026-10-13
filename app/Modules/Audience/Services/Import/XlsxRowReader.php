<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use DateTimeInterface;
use Generator;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * XLSX streamelve (openspout, MIT) — a munkalap XML-jét SAX-szerűen olvassa, a megosztott
 * szövegeket pedig fájl-gyorsítótárba teszi, tehát a memória nem nő a sorok számával.
 *
 * Az ÜRES sorokat az olvasó megőrzi (a sorszám így egyezik a táblázatkezelőéval), mi
 * ugyanúgy átugorjuk, mint a CSV-nél.
 */
final class XlsxRowReader implements ImportRowReader
{
    /**
     * A plafonok EXPLICIT átadhatók (D221): a gyerekfolyamat (`IsolatedXlsxRowReader`) Laravel nélkül
     * fut, ott nincs `config()`. Ha nincsenek megadva, a konfigurációból jönnek (a régi viselkedés).
     */
    public function __construct(
        private readonly string $path,
        private readonly ?string $sheet = null,
        private readonly ?string $tempFolder = null,
        private readonly ?int $maxColumns = null,
        private readonly ?int $maxCell = null,
        private readonly ?int $maxRows = null,
    ) {}

    public function rows(): Generator
    {
        $maxColumns = $this->maxColumns ?? (int) config('audience.import.max_columns');
        $maxCell = $this->maxCell ?? (int) config('audience.import.max_cell_length');
        $maxRows = $this->maxRows ?? (int) config('audience.import.max_rows');

        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true, tempFolder: $this->tempFolder));
        $reader->open($this->path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($this->sheet !== null && $sheet->getName() !== $this->sheet) {
                    continue;
                }

                $line = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $line++;

                    if ($line > $maxRows + 1) {
                        throw new ImportLimitExceeded('rows');
                    }

                    // A cellák TÖMBJE lyukas lehet (kihagyott oszlopok): pozíció szerint töltjük.
                    $cells = array_fill(0, $row->getNumCells(), '');

                    foreach ($row->cells as $position => $cell) {
                        $cells[$position] = $this->text($cell->getValue());
                    }

                    // A záró üres cellákat levágjuk: az XLSX gyakran „kitölti" a sort.
                    while ($cells !== [] && end($cells) === '') {
                        array_pop($cells);
                    }

                    if ($cells === []) {
                        continue;
                    }

                    if (count($cells) > $maxColumns) {
                        throw new ImportLimitExceeded('columns');
                    }

                    yield $line => array_map(
                        static fn (string $value): string => mb_strlen($value) > $maxCell ? mb_substr($value, 0, $maxCell) : $value,
                        $cells,
                    );
                }

                return; // csak EGY munkalapot olvasunk
            }
        } finally {
            $reader->close();
        }
    }

    private function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            $value instanceof DateTimeInterface => $value->format('H:i:s') === '00:00:00'
                ? $value->format('Y-m-d')
                : $value->format('Y-m-d H:i:s'),
            is_float($value) => $this->number($value),
            is_scalar($value) => trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value)),
            default => '',
        };
    }

    /** `3.0` → `3`, de `3.5` marad — és sosem tudományos alak (`3.6E+10`). */
    private function number(float $value): string
    {
        if (floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
    }
}
