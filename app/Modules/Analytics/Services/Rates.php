<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

/**
 * A RÁTÁK NEVEZŐI — egy helyen, kimondva (72. kritérium: „a ráták helyesek").
 *
 * ## Miért külön osztály egy osztásért
 *
 * Mert a nevező VÁLASZTÁS, és a választás minden rátánál más. Ha ez a hívási helyeken
 * lenne, valahol biztosan az „elfogadott" kerülne a „kézbesített" helyére — és a szám
 * SZEBB lenne, nem hibás-kinézetű. Egy hibás rátát senki nem vesz észre: nincs kivétel,
 * nincs piros teszt, csak egy kampány-riport, ami alapján a tenant rossz döntést hoz.
 *
 * ## A DÖNTÉSEK, és miért ezek
 *
 * - **nyitás / klikk / panasz / leiratkozás → a KÉZBESÍTETT-re.** Aki nem kapta meg a
 *   levelet, nem is nyithatta meg. Az elfogadottra osztva a rátánk minden bounce-szal
 *   romlana, holott a törzs ugyanolyan jó.
 * - **bounce → az ELFOGADOTT-ra.** A bounce épp a kézbesítés kudarca: a kézbesítettre
 *   osztva a nevezőből kimaradnának pontosan azok az esetek, amiket számolunk — és a
 *   bounce-arány mindig szebb lenne a valóságnál. Ez a szám a kézbesíthetőségi megfelelés
 *   alapja (spam-ráta < 0,3%), tehát nem lehet kozmetikázva.
 * - **CTOR (klikk a nyitókhoz) → az EGYEDI NYITÓK-ra.** Ez mutatja a TÖRZS erejét: a
 *   nyitási arányt a tárgysor dönti el, a CTOR-t a tartalom.
 * - **EGYEDI számláló a nevezőben és a számlálóban is.** Egy Apple MPP-s postafiók
 *   ugyanazt a levelet tízszer „megnyitja"; a nyers eseményszámmal osztva 300%-os nyitási
 *   arányt kapnánk.
 *
 * ## AZ APPLE MPP figyelmeztetése
 *
 * A nyitás mérése 2021 óta MEGBÍZHATATLAN: a Mail Privacy Protection proxyja MINDEN levelet
 * letölt, tehát „megnyit". Ezért a nyitási arány felfelé torzul, és a variánsok között
 * elmosódik — a `VariantWinner` docblockja is ezért ajánlja a klikket győztes-kritériumnak.
 * A számot KISZÁMOLJUK (a tenant elvárja, és a trendje használható), de nem hazudjuk
 * pontosnak.
 */
final class Rates
{
    /**
     * A SZÁZALÉK — nulla nevezőnél NULLA, nem kivétel és nem 100%.
     *
     * A `0/0` háromféleképp romolhat el: kivétellel (a riport-oldal nem nyílik meg), `NAN`-nal
     * (a JSON-ban `null`, a felületen „—"), vagy — a legrosszabb — 100%-kal, ha valaki
     * „biztonságosan" 1-re állítja a nevezőt. Egy még ki nem küldött kampány riportja
     * NULLÁKAT mutat; ez az egyetlen igaz válasz.
     */
    public static function percent(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round($numerator / $denominator * 100, 2);
    }
}
