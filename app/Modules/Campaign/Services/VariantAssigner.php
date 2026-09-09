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

        /*
         | A MARADÉK a LEGNAGYOBB TÖRTRÉSZ elve szerint oszlik el, nem „mindig az utolsóé".
         |
         | Az utolsó variánsnak adott maradék mérve elrontotta a felosztást: 50/50/0-nál,
         | 101 címzettel a 0%-OS variáns kapott 1 címzettet, egyetlen címzettnél pedig
         | Ő KAPTA MEG AZ EGYETLENT — vagyis a „ne menjen ki" beállítás küldött levelet.
         |
         | A helyes szabály: a törtrészek szerint csökkenő sorrendben osztjuk szét a
         | maradékot, és CSAK a pozitív súlyú variánsok között. Egyenlő törtrésznél a
         | kisebb index nyer — enélkül a felosztás nem lenne determinisztikus, és egy
         | újrapróbálás más embernek adna más variánst.
         */
        $shares = [];
        $fractions = [];
        $assigned = 0;

        foreach ($variants as $index => $variant) {
            $weight = (int) $variant->weight;
            $exact = $total * $weight / 100;
            $shares[$index] = (int) floor($exact);
            $assigned += $shares[$index];

            if ($weight > 0) {
                $fractions[$index] = $exact - $shares[$index];
            }
        }

        // Csökkenő törtrész, egyenlőségnél növekvő index.
        $order = array_keys($fractions);

        usort($order, static fn (int $a, int $b): int => $fractions[$b] <=> $fractions[$a] ?: $a <=> $b);

        for ($i = 0, $left = $total - $assigned; $i < $left; $i++) {
            if ($order === []) {
                // MINDEN SÚLY NULLA: nincs kinek adni. Ez az `assertReady` súlyellenőrzésén
                // amúgy sem menne át (az összegnek 100-nak kell lennie), de a hozzárendelés
                // nem támaszkodhat egy másik osztály ellenőrzésére.
                break;
            }

            $shares[$order[$i % count($order)]]++;
        }

        $plan = [];
        $position = 0;

        foreach ($variants as $index => $variant) {
            for ($i = 0; $i < $shares[$index]; $i++) {
                $plan[$position++] = (int) $variant->getKey();
            }
        }

        return $plan;
    }
}
