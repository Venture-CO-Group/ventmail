<?php

declare(strict_types=1);

namespace App\Modules\Journey\Actions;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Models\JourneyRun;
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
 * összehasonlítás adja (`journeys:detect-segment-entries` — `DetectSegmentEntriesCommand`),
 * ami a szegmens jelenlegi tagságát a `JourneyTrigger` kapuihoz méri. Ez tudatos közelítés: a belépés a KÖVETKEZŐ futásnál
 * derül ki, nem a pillanatában — egy percnyi késés egy marketing-automatizálásnál nem
 * számít, egy hamis „belépés" viszont fölös levelet küld.
 */
final class FireJourneyEvent
{
    public function __construct(
        private readonly JourneyTrigger $trigger,
        private readonly SegmentEngine $segments,
    ) {}

    /**
     * CÍMKE-HOZZÁADÁS — a hívó dönti el, hogy felhasználói cselekedet-e.
     *
     * ## FIGYELEM: ennek MA NINCS hívója, és ez tudatos állapot
     *
     * A review joggal találta meg, hogy a `tag_added` trigger élesben nem tüzel. Az ok
     * viszont nem elfelejtett bekötés: az appban ma EGYETLEN hely ír kontakt-címkét, a
     * journey `add_tag` lépése (`grep -rn "tags()->" app` → csak a `JourneyEngine`). Nincs
     * felület, nincs `/v1` végpont, nincs import, ami címkézne.
     *
     * És épp azt az egy helyet NEM köthetjük be: a journey `add_tag` lépése így ÖNMAGÁT
     * indíthatná el egy másik journey-ben, két journey egymást tüzelve pedig végtelen
     * ciklust adna — az osztály docblockja pontosan ezt írja le.
     *
     * A bekötés tehát a címkézés BELÉPÉSI PONTJÁVAL együtt jön (Audience tag-API vagy
     * felület), és addig a `PHASE.md` backlogjában áll. A metódus itt marad, mert a
     * kontraktus ez lesz — és a teszt fedi, hogy amikor lesz hívó, a viselkedés kész.
     *
     * @return list<JourneyRun>
     */
    public function tagAdded(Contact $contact, ContactTag $tag): array
    {
        return $this->trigger->fire(TriggerType::TagAdded, (string) $contact->email, [
            'tag' => (string) $tag->uuid,
        ]);
    }

    /**
     * FELIRATKOZÁS LISTÁRA (v1.1) — a double opt-in MEGERŐSÍTÉSEKOR hívódik
     * (`ConfirmSubscriptionController`), nem a puszta kérésnél: a „feliratkozott" itt
     * megerősített tagságot jelent.
     *
     * @return list<JourneyRun>
     */
    public function listSubscribed(Contact $contact, ContactList $list): array
    {
        return $this->trigger->fire(TriggerType::ListSubscribe, (string) $contact->email, [
            'list' => (string) $list->uuid,
        ]);
    }

    /**
     * CÍMKE ELTÁVOLÍTÁSA (v1.1) — ugyanaz a szabály, mint a `tagAdded()`-nél: a hívó dönti el,
     * hogy felhasználói cselekedet-e. A journey saját `update_contact` lépése NEM hívja (két
     * journey egymást tüzelve végtelen ciklust adna).
     *
     * @return list<JourneyRun>
     */
    public function tagRemoved(Contact $contact, ContactTag $tag): array
    {
        return $this->trigger->fire(TriggerType::TagRemoved, (string) $contact->email, [
            'tag' => (string) $tag->uuid,
        ]);
    }

    /**
     * KONTAKT-MEZŐ VÁLTOZÁSA (v1.1). A mező kulcsa a payloadban; az új érték a futás
     * kontextusába kerül (a `context_equals` feltétel szűrhet rá).
     *
     * @return list<JourneyRun>
     */
    public function fieldChanged(Contact $contact, string $field, ?string $value = null): array
    {
        return $this->trigger->fire(TriggerType::FieldChanged, (string) $contact->email, [
            'field' => $field,
            'value' => $value === null ? null : mb_substr($value, 0, 200),
        ]);
    }

    /**
     * KAMPÁNY-INTERAKCIÓ (v1.1): megnyitás vagy kattintás egy kampány levelén.
     *
     * A nyitás egy része gépi (Apple MPP, képproxy) — a journey ezt nem tudja
     * megkülönböztetni, ugyanúgy, mint a kampány-riport (73. kritérium).
     *
     * @return list<JourneyRun>
     */
    public function campaignInteraction(string $email, string $campaignUuid, string $interaction): array
    {
        return $this->trigger->fire(TriggerType::CampaignInteraction, $email, [
            'campaign' => $campaignUuid,
            'interaction' => $interaction,
        ]);
    }

    /**
     * API-ESEMÉNY — a hitelesített hívó szava, tehát a forrás bizonyított.
     *
     * A végpont a `POST /v1/journeys/events` (`JourneyEventController`), `api.idempotency`
     * mellett: egy újrapróbált integráció-hívás különben második futást indítana ugyanarra
     * az emberre.
     *
     * @param  array<string, mixed>  $payload
     * @return list<JourneyRun>
     */
    public function apiEvent(string $email, string $event, array $payload = []): array
    {
        return $this->trigger->fire(TriggerType::ApiEvent, $email, array_merge($payload, [
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
