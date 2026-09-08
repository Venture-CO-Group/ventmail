<?php

declare(strict_types=1);

namespace App\Modules\Journey\Actions;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Services\JourneyTrigger;

/**
 * A TÖBBI TRIGGER-FORRÁS (69. kritérium): címke, szegmensbe lépés, API-esemény.
 *
 * ## Miért külön action, és miért nem modell-hook
 *
 * A címke-hozzáadás a `Contact::tags()` relációján történik, több helyről (felület, import,
 * journey `add_tag` lépés, CRM-szinkron). Egy modell-hook mindegyiket lefedné — de épp
 * ezért lenne rossz: a journey `add_tag` lépése így ÖNMAGÁT indíthatná el egy másik
 * journey-ben, és két journey egymást tüzelve végtelen ciklust adna.
 *
 * A trigger ezért EXPLICIT: aki címkéz, az dönti el, hogy ez a felhasználó cselekedete-e
 * (tüzeljen) vagy egy automatizálás mellékhatása (ne tüzeljen).
 *
 * ## A SZEGMENSBE LÉPÉS nem esemény, hanem ÁLLAPOT
 *
 * A szegmens egy lekérdezés: nincs „belépés" pillanata. Ezért ezt egy ÜTEMEZETT
 * összehasonlítás adja (`journeys:detect-segment-entries`), ami a szegmens jelenlegi
 * tagságát a legutóbbi futáshoz méri. Ez tudatos közelítés: a belépés a KÖVETKEZŐ futásnál
 * derül ki, nem a pillanatában — egy percnyi késés egy marketing-automatizálásnál nem
 * számít, egy hamis „belépés" viszont fölös levelet küld.
 */
final class FireJourneyEvent
{
    public function __construct(
        private readonly JourneyTrigger $trigger,
        private readonly SegmentEngine $segments,
    ) {}

    /** CÍMKE-HOZZÁADÁS — a hívó dönti el, hogy felhasználói cselekedet-e. */
    public function tagAdded(Contact $contact, ContactTag $tag): void
    {
        $this->trigger->fire(TriggerType::TagAdded, (string) $contact->email, [
            'tag' => (string) $tag->uuid,
        ]);
    }

    /**
     * API-ESEMÉNY — a hitelesített hívó szava, tehát a forrás bizonyított.
     *
     * @param  array<string, mixed>  $payload
     */
    public function apiEvent(string $email, string $event, array $payload = []): void
    {
        $this->trigger->fire(TriggerType::ApiEvent, $email, array_merge($payload, [
            'event' => $event,
        ]));
    }

    /**
     * SZEGMENSBE LÉPÉS — ütemezett összehasonlítással.
     *
     * @return int a beléptetett futások száma
     */
    public function segmentEntered(Segment $segment): int
    {
        $entered = 0;

        // A SZEGMENS jelenlegi tagsága. A `JourneyTrigger` szűri, hogy ki van már benne
        // (aktív futás) és ki nem léphet be (suppression, entitlement, újrabelépés) — itt
        // nem duplikáljuk azt a logikát.
        $this->segments->query((array) $segment->definition)
            ->orderBy('id')
            ->chunkById(500, function ($contacts) use ($segment, &$entered): void {
                foreach ($contacts as $contact) {
                    $runs = $this->trigger->fire(
                        TriggerType::SegmentEntered,
                        (string) $contact->email,
                        ['segment' => (string) $segment->uuid],
                    );

                    $entered += count($runs);
                }
            });

        return $entered;
    }
}
