<?php

declare(strict_types=1);

namespace App\Modules\Template\Services\Assets;

use RuntimeException;

/**
 * Az `ImageInspector` elutasítása. Az `$reason` (`type` | `dimensions` | `processing`) a hívó
 * modul felhasználói üzenetére fordul — a szolgáltatás nem tud a hívó nyelvi kulcsairól.
 */
final class ImageRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('A kép elutasítva: '.$reason);
    }
}
