<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use RuntimeException;

/**
 * Az import-fájl átlépett egy határt (sor, oszlop, méret, zip-bomba) vagy nem az, aminek
 * mondja magát. A `reason` egy fordítható kulcs-szuffix (`ventmail.audience.import.limit.*`),
 * hogy a felület emberi nyelven mondhassa meg, MIT kell javítani.
 */
final class ImportLimitExceeded extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
