<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use Generator;
use RuntimeException;

/**
 * CSV streamelve (`fgetcsv`) — O(1) memória a fájl méretétől függetlenül.
 *
 * A Windows-1250 fájlt cellánként alakítjuk UTF-8-ra (nem az egész fájlt), mert az
 * elválasztó és az idézőjel ASCII, tehát a bájt-szintű CSV-értelmezés mindkét kódolásban
 * helyes — és így sosem kell a teljes fájlt memóriába venni.
 */
final class CsvRowReader implements ImportRowReader
{
    public function __construct(
        private readonly string $path,
        private readonly string $delimiter = ',',
        private readonly string $encoding = 'UTF-8',
    ) {}

    public function rows(): Generator
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Az import-fájl nem nyitható meg.');
        }

        $maxColumns = (int) config('audience.import.max_columns');
        $maxCell = (int) config('audience.import.max_cell_length');
        $maxRows = (int) config('audience.import.max_rows');
        $convert = $this->encoding === 'Windows-1250';

        try {
            $line = 0;

            // Az `escape` paraméter SZÁNDÉKOSAN üres: a PHP-s `\` escape nem CSV-szabvány, és
            // egy `\"`-re végződő mező egész sorokat nyelne el.
            while (($record = fgetcsv($handle, (int) config('audience.import.max_line_bytes'), $this->delimiter, '"', '')) !== false) {
                $line++;

                if ($line > $maxRows + 1) {
                    throw new ImportLimitExceeded('rows');
                }

                if ($record === [null]) {
                    continue; // üres sor
                }

                if (count($record) > $maxColumns) {
                    throw new ImportLimitExceeded('columns');
                }

                if ($line === 1 && isset($record[0])) {
                    $record[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $record[0]);
                }

                $cells = [];

                foreach ($record as $cell) {
                    $value = (string) $cell;

                    // NUL-bájt a cellában (a felmérés a fájl elején kiszűri, de a fájl közepén is
                    // lehet): elutasítjuk; a többi vezérlőkaraktert eldobjuk.
                    if (str_contains($value, "\0")) {
                        throw new ImportLimitExceeded('encoding');
                    }

                    $value = (string) preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

                    if ($convert) {
                        $value = Windows1250::toUtf8($value);
                    }

                    $cells[] = mb_strlen($value) > $maxCell ? mb_substr($value, 0, $maxCell) : $value;
                }

                yield $line => $cells;
            }
        } finally {
            fclose($handle);
        }
    }
}
