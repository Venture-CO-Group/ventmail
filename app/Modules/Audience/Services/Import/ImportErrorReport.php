<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use App\Support\CsvSafe;
use RuntimeException;

/**
 * A HIBARIPORT CSV streamelt írója: minden, ami NEM került be (hibás vagy kihagyott sor),
 * a fájlbeli sorszámmal, a címmel, a névvel, a kóddal és emberi üzenettel.
 *
 * Minden cella `CsvSafe`-en megy át: a címet és a nevet a FELTÖLTŐ fájl adja, tehát bármi
 * lehet benne — a képlet-kezdetű cella a riportot megnyitó gépen kódot futtathatna.
 */
final class ImportErrorReport
{
    /** @var resource */
    private $handle;

    private int $rows = 0;

    public function __construct(string $absolutePath)
    {
        $directory = dirname($absolutePath);

        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('A hibariport könyvtára nem hozható létre.');
        }

        $handle = fopen($absolutePath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('A hibariport nem írható.');
        }

        $this->handle = $handle;

        // UTF-8 BOM: a magyar Excel enélkül Windows-1250-nek olvassa az ékezetes neveket.
        fwrite($this->handle, "\xEF\xBB\xBF");
        $this->put(['line', 'email', 'name', 'status', 'reason', 'message']);
    }

    public function add(int $line, string $email, string $name, string $status, string $reason, string $message): void
    {
        $this->rows++;
        $this->put([(string) $line, $email, $name, $status, $reason, $message]);
    }

    public function count(): int
    {
        return $this->rows;
    }

    public function close(): void
    {
        fclose($this->handle);
    }

    /** @param list<string> $cells */
    private function put(array $cells): void
    {
        fputcsv($this->handle, array_map(CsvSafe::cell(...), $cells), ',', '"', '');
    }
}
