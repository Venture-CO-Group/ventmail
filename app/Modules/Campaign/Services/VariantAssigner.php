<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use App\Modules\Campaign\Models\Campaign;

/**
 * A/B VARIÁNS-HOZZÁRENDELÉS (64. kritérium).
 *
 * ## Miért DETERMINISZTIKUS, és miért nem véletlen
 *
 * A naiv megoldás minden címzettnél sorsol. Két baja van:
 *
 *  1. **Kis közönségnél félrecsúszik.** 100 címzettnél egy 50/50-es sorsolás könnyen ad
 *     58/42-t — és az A/B teszt eredményét a mintavételi hiba dönti el, nem a tárgysor.
 *  2. **Nem megismételhető.** Egy újrapróbálás MÁS variánst adna ugyanannak az embernek,
 *     tehát egy ember két különböző levelet kapna, a mérés pedig értelmét veszti.
 *
 * Ezért a hozzárendelés POZÍCIÓ-alapú: a rendezett címzett-listát a súlyok arányában
 * osztjuk fel. Ugyanaz a lista ugyanazt a felosztást adja, a súlyok pedig PONTOSAN
 * teljesülnek (a maradékot az utolsó variáns kapja, nem tűnik el).
 *
 * A SZÓRÁS a rendezésből jön: a lista cím szerint rendezett, tehát a felosztás nem korrelál
 * semmilyen üzleti tulajdonsággal (regisztráció ideje, csomag). Ha az id szerint
 * rendeznénk, az „A" variáns a régi ügyfeleket kapná — és a mérés a variánst hasonlítaná
 * össze két különböző közönségen.
 */
final class VariantAssigner
{
    /**
     * A címzett-POZÍCIÓK → variáns-id térkép.
     *
     * @return array<int, int|null>
     */
    public function plan(Campaign $campaign, int $total): array
    {
        $variants = $campaign->variants()->orderBy('id')->get();

        if ($variants->count() < 2 || $total === 0) {
            return [];
        }

        $plan = [];
        $position = 0;
        $assigned = 0;
        $last = $variants->count() - 1;

        foreach ($variants as $index => $variant) {
            // AZ UTOLSÓ variáns kapja a MARADÉKOT. Kerekítésnél máskülönben néhány címzett
            // kimaradna a felosztásból — és azok nem kapnának levelet.
            $share = $index === $last
                ? $total - $assigned
                : (int) floor($total * ((int) $variant->weight) / 100);

            for ($i = 0; $i < $share; $i++) {
                $plan[$position++] = (int) $variant->getKey();
            }

            $assigned += $share;
        }

        return $plan;
    }
}
