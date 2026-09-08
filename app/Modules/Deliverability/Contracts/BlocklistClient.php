<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Contracts;

use App\Modules\Deliverability\Data\BlocklistVerdict;

/**
 * BLOCKLIST-lekérdezés (43. kritérium).
 *
 * Interfész mögött, mert külső gazda — és mert a fake determinisztikus kontraktus. A valódi
 * lekérdezés DNS-alapú (fordított IP + a zóna neve), de a szolgáltatók viselkedése eltér:
 * némelyik a lekérdezés-számot is korlátozza, és a túllépés BLOKKOLT válaszként jön vissza,
 * ami hamis „listán van"-t adna.
 */
interface BlocklistClient
{
    public function check(string $target, string $zone): BlocklistVerdict;
}
