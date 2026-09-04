<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Testing;

use App\Modules\Inbound\Contracts\VirusScanner;
use App\Modules\Inbound\Data\ScanResult;

/**
 * Determinisztikus szkenner a tesztekhez.
 *
 * Az EICAR-mintát ismeri fel — ez az iparági, SZÁNDÉKOSAN ártalmatlan tesztfájl, amit minden
 * valódi vírusirtó is fertőzöttnek jelöl. Így a teszt ugyanazt a viselkedést méri, amit a
 * ClamAV adna, valódi kártevő nélkül.
 */
final class FakeVirusScanner implements VirusScanner
{
    public const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public bool $available = true;

    public function scan(string $filename, string $contents): ScanResult
    {
        if (! $this->available) {
            return ScanResult::unavailable('A szkenner nem elérhető.');
        }

        return str_contains($contents, self::EICAR)
            ? ScanResult::infected('Eicar-Test-Signature')
            : ScanResult::clean();
    }
}
