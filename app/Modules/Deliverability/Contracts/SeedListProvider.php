<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Contracts;

use App\Modules\Deliverability\Data\SeedPlacement;

/**
 * SEED / INBOX PLACEMENT (45. kritérium).
 *
 * A seed-lista olyan postafiókok halmaza a nagy szolgáltatóknál (Gmail, Outlook, Yahoo…),
 * amikbe küldünk, majd MEGNÉZZÜK, hova érkezett: beérkező, spam, promóciók — vagy sehova.
 *
 * Ez az EGYETLEN mérés, ami megmondja, mit lát az olvasó. A saját naplónk `delivered`-je
 * annyit jelent, hogy a fogadó ÁTVETTE a levelet — azt nem, hogy meg is mutatta.
 *
 * Interfész mögött, mert külső gazda (a seed-postafiókok kezelése és az olvasás
 * szolgáltatás), és mert a teszt determinisztikus kell legyen.
 */
interface SeedListProvider
{
    /** @return list<string> a seed-címek, amikre küldeni kell */
    public function addresses(): array;

    /** @return list<SeedPlacement> hova érkeztek a levelek */
    public function placements(string $testUuid): array;
}
