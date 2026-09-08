<?php

declare(strict_types=1);

namespace App\Modules\Audience\Testing;

use App\Modules\Audience\Contracts\VentureOsSync;
use App\Modules\Audience\Data\CrmContact;

/**
 * Determinisztikus ventOS-szinkron a tesztekhez (56. kritérium).
 *
 * A `pushed` tömb a KONTRAKTUS ellenőrzésére van: a teszt megnézheti, mit adtunk át, és
 * hogy az idempotencia tényleg idempotens-e (ugyanaz a kontakt kétszer = egy hatás).
 */
final class FakeVentureOsSync implements VentureOsSync
{
    /** @var list<CrmContact> */
    public array $remote = [];

    /** @var list<CrmContact> */
    public array $pushed = [];

    public function pull(?string $since = null): array
    {
        if ($since === null) {
            return $this->remote;
        }

        return array_values(array_filter(
            $this->remote,
            fn (CrmContact $contact) => $contact->updatedAt === null || $contact->updatedAt->gt($since)
        ));
    }

    public function push(array $contacts): int
    {
        foreach ($contacts as $contact) {
            $this->pushed[] = $contact;
        }

        return count($contacts);
    }
}
