<?php

declare(strict_types=1);

namespace App\Modules\Journey\Enums;

/**
 * A JOURNEY LÉPÉSTÍPUSOK (68. kritérium).
 *
 * A készlet SZŰK és zárt. Egy „bármit tudó" lépés (kód futtatása, webhook-hívás) a
 * journey-motort általános feladat-futtatóvá tenné, aminek a hibái a küldési útra hatnának:
 * egy lassú külső hívás a journey-sort tömné meg, és a levelek csúsznának.
 */
enum StepType: string
{
    /** Levél küldése — a `SendMessage` actionön, tehát mind a tíz kapun át. */
    case SendEmail = 'send_email';

    /** Várakozás. A futás `waiting` állapotba kerül, és `resume_at`-ot kap. */
    case Wait = 'wait';

    /** Elágazás: a `next_step` az „igaz", az `else_step` a „hamis" ág. */
    case Condition = 'condition';

    case AddTag = 'add_tag';

    case RemoveTag = 'remove_tag';

    /** Kilépés a journey-ből. A lánc vége implicit kilépés; ez az EXPLICIT. */
    case Exit = 'exit';

    /** Azonnal végrehajtható-e, vagy felfüggeszti a futást? */
    public function suspends(): bool
    {
        return $this === self::Wait;
    }
}
