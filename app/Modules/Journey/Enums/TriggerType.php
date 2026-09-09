<?php

declare(strict_types=1);

namespace App\Modules\Journey\Enums;

/**
 * A JOURNEY TRIGGEREI (69. kritérium).
 *
 * Öt forrás, és az utolsó a legérdekesebb: az INBOUND E-MAIL. Ez az A4-ből jön (MX-fogadás
 * + route-motor), és azt jelenti, hogy egy beérkező levél indíthat automatizálást — például
 * egy „info@" címre írt kérdés elindít egy támogatási sorozatot.
 *
 * Épp ezért a legkockázatosabb is: a beérkező levél FELADÓJA HAMISÍTHATÓ. Bárki írhat egy
 * levelet a világon `anna@pelda.hu` feladóval, és ha ez marketing-ágat indíthatna, akkor egy
 * idegen bármikor levelet küldhetne Anna nevében — Anna hozzájárulása nélkül. Ezért szól a
 * 71. kritérium arról, hogy az inbound trigger CSAK MEGERŐSÍTETT kontaktnál indíthat
 * marketing ágat.
 */
enum TriggerType: string
{
    case FormSignup = 'form_signup';
    case TagAdded = 'tag_added';
    case SegmentEntered = 'segment_entered';
    case ApiEvent = 'api_event';
    case InboundEmail = 'inbound_email';

    /**
     * HAMISÍTHATÓ-e a trigger forrása?
     *
     * Az inbound e-mail feladója igen: az SMTP `MAIL FROM` és a `From:` fejléc is a küldő
     * szava. A többi trigger a MI rendszerünkből jön (űrlap-beküldés, címkézés,
     * szegmens-tagság, hitelesített API-hívás), tehát a forrás bizonyított.
     */
    public function isSpoofable(): bool
    {
        return $this === self::InboundEmail;
    }
}
