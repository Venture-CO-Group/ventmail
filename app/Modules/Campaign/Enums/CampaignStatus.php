<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Enums;

/**
 * A KAMPÁNY ÉLETCIKLUSA (62. kritérium).
 *
 * Az átmenetek KIMONDVA vannak, nem a hívók fegyelmén. Egy állapotgép, ami csak
 * dokumentációban létezik, néhány hónap alatt szétcsúszik: valaki „csak most egyszer"
 * átállítja a `sent`-et `sending`-re, és a kampány újra kimegy mindenkinek.
 *
 * A tiltott átmenetek közül kettőt érdemes kiemelni:
 *
 *  - **`sent` → bármi**: a kiküldött kampány VÉGLEGES. Nem lehet újraindítani, nem lehet
 *    „javítani": a levél már a címzettek postafiókjában van. Aki javítani akar, új
 *    kampányt indít;
 *  - **`canceled` → `sending`**: a visszavont kampány nem éled újra. A `paused` az, ami
 *    folytatható — a kettő szándékosan KÜLÖN állapot, mert a „szüneteltetem, mert rossz a
 *    tárgy" és a „leállítom, mert nem kell" két különböző döntés.
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Paused = 'paused';
    case Sent = 'sent';
    case Canceled = 'canceled';

    /**
     * Az ENGEDÉLYEZETT átmenetek.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            // A vázlat ütemezhető, azonnal indítható, vagy eldobható.
            self::Draft->value => [self::Scheduled->value, self::Sending->value, self::Canceled->value],
            // Az ütemezett visszavehető vázlatba (a felhasználó még szerkeszt), indulhat, vagy
            // lemondható.
            self::Scheduled->value => [self::Draft->value, self::Sending->value, self::Canceled->value],
            // A küldés szüneteltethető, befejeződhet, vagy megszakítható.
            self::Sending->value => [self::Paused->value, self::Sent->value, self::Canceled->value],
            /*
             | A szüneteltetett FOLYTATHATÓ — ez a lényege. És BEFEJEZHETŐ is:
             |
             | ha a szüneteltetés közben minden címzett kiment (az utolsó köteg még futott),
             | a folytatásnak nincs mit tennie. `paused → sent` nélkül a kampány ilyenkor
             | vagy örökre `paused` maradna, vagy egy `sending` állapoton át kellene
             | átvezetni, ami a felületen egy pillanatra „küldés folyamatban"-t villantana
             | egy már befejezett kampányra.
             */
            self::Paused->value => [self::Sending->value, self::Sent->value, self::Canceled->value],
            // A KIKÜLDÖTT VÉGLEGES: a levél a címzettek postafiókjában van.
            self::Sent->value => [],
            // A visszavont nem éled újra.
            self::Canceled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::transitions()[$this->value] ?? [], true);
    }

    /** Szerkeszthető-e még a kampány (célzás, sablon, tárgy)? */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }

    /** Véglegesen zárt állapot-e? */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Sent, self::Canceled], true);
    }
}
