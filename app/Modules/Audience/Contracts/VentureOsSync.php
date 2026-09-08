<?php

declare(strict_types=1);

namespace App\Modules\Audience\Contracts;

use App\Modules\Audience\Data\CrmContact;

/**
 * KÉTIRÁNYÚ CRM-szinkron a ventOS-szel (56. kritérium).
 *
 * Interfész mögött, mert külső gazda — és mert a ventOS még nem él. A fake a teszttel
 * fedett KONTRAKTUS, nem csendes helyettesítő: élesben a bekötés HANGOSAN dob, ahogy a
 * `ConsentChecker` és a `VentPayClient` esetében is.
 *
 * A kulcs az `external_id`, és a konfliktus-feloldás LAST-WRITE-WINS az `updated_at`
 * alapján. Ez tudatos egyszerűsítés: a mezőnkénti összefésülés (CRDT-szerű megoldás) egy
 * címlistánál többet ront, mint javít — két rendszer „félig frissült" kontaktja rosszabb,
 * mint egy egyértelműen a későbbi írásból származó.
 */
interface VentureOsSync
{
    /** @return list<CrmContact> a ventOS-ből érkező, MÓDOSULT kontaktok */
    public function pull(?string $since = null): array;

    /** @param list<CrmContact> $contacts */
    public function push(array $contacts): int;
}
