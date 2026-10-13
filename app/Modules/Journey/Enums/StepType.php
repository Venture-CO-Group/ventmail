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

    /*
     | v1.1 · 6. modul — a VÁSZON új csomópontjai (D5).
     |
     | A fenti indoklás (szűk, zárt készlet) továbbra is áll: a webhook csomópont NEM általános
     | HTTP-hívás, hanem egy MEGLÉVŐ, a Webhooks modulban regisztrált végpontra küldött aláírt
     | esemény — a `DeliverWebhook` job viszi (SSRF-őr, időkorlát, HMAC, újrapróbálás,
     | dead-letter, replay), a `webhooks` soron, tehát a journey-sort és a küldést nem lassítja.
     */

    /** Többágú elágazás: feltételek sorrendben, az első igaz ág nyer, különben `else`. */
    case Split = 'split';

    /** A/B ágak arányokkal — determinisztikus sorsolás a futás és a lépés azonosítójából. */
    case AbSplit = 'ab_split';

    /** Kontakt frissítése: mező beállítása, címke hozzáadása / eltávolítása. */
    case UpdateContact = 'update_contact';

    /** Lista-tagság: hozzáadás vagy eltávolítás. */
    case ListMembership = 'list_membership';

    /** Aláírt esemény egy regisztrált webhook-végpontra. */
    case Webhook = 'webhook';

    /** Cél: a futás SIKERREL zárul (`goal_reached`). */
    case Goal = 'goal';

    /** Azonnal végrehajtható-e, vagy felfüggeszti a futást? */
    public function suspends(): bool
    {
        return $this === self::Wait;
    }
}
