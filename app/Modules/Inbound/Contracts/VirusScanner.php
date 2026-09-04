<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Contracts;

use App\Modules\Inbound\Data\ScanResult;

/**
 * Csatolmány-szkennelés (35. kritérium; a gazda később a ventSec).
 *
 * Interfész mögött, mert külső gazda — és mert a fake így teszttel fedett kontraktus lesz.
 * A hívó SOSEM feltételezi, hogy a szkenner elérhető: a kiesés `unavailable`, és a
 * csatolmány olyankor NEM tölthető le. A fail-open itt azt jelentené, hogy a szkennerünk
 * kiesésének idején mi terjesztjük a kártevőt.
 */
interface VirusScanner
{
    public function scan(string $filename, string $contents): ScanResult;
}
