<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use Generator;

/**
 * STREAMELT sorolvasó — a feldolgozás egyetlen bemenete, formátumtól függetlenül.
 *
 * A kulcs a fájlbeli SORSZÁM (1-alapú, a fejlécet is számolja, az üres sorokat átugorja de
 * megtartja a számot), hogy a hibariportban a felhasználó a táblázatkezelőjében is
 * megtalálja a sort. Az érték a cellák listája, UTF-8-ban, megvágott hosszal.
 */
interface ImportRowReader
{
    /** @return Generator<int, list<string>> */
    public function rows(): Generator;
}
