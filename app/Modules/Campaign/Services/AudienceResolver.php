<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Campaign\Models\Campaign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A CÉLZÁS FELOLDÁSA: lista ∪ szegmens MÍNUSZ kizárás (63. kritérium).
 *
 * ## A halmaz-műveletek sorrendje nem mindegy
 *
 * Először UNIÓ (a célzott listák és szegmensek együtt), utána KIVONÁS (a kizártak). A másik
 * sorrend — kizárás, majd unió — azt jelentené, hogy egy kizárt cím visszakerül, ha egy
 * másik célzott listán is szerepel. A kizárás SZÁNDÉKA az, hogy az adott ember NE kapja meg;
 * ezt egy unió nem írhatja felül.
 *
 * ## A DEDUPLIKÁCIÓ nem opcionális
 *
 * A lista ∪ szegmens ugyanazt a címet több forrásból is adhatja. Deduplikáció nélkül a
 * címzett kétszer (háromszor) kapná meg ugyanazt a kampányt — ez a legláthatóbb hiba, amit
 * egy hírlevélküldő el tud követni, és azonnal spam-panasszá válik. A kulcs a NORMALIZÁLT
 * cím, nem a kontakt id-je: két kontakt-sor ugyanarra a címre (import + űrlap) egyetlen
 * embert jelent.
 *
 * ## A BECSLÉS ÉS A VALÓSÁG
 *
 * A `estimate()` ugyanazt a lekérdezést futtatja, mint a `resolve()` — csak számol. Ha a
 * kettő külön logika lenne, a felületen mutatott szám és a kiküldött levelek száma
 * eltérne, és a kampány-riport hazudna. A számot NEM cache-eljük hosszan: a közönség
 * folyamatosan változik, és egy órás becslés rosszabb, mint a friss.
 */
final class AudienceResolver
{
    public function __construct(private readonly SegmentEngine $segments) {}

    /**
     * A CÉLZOTT címek — normalizálva, deduplikálva, rendezve.
     *
     * @return list<array{email: string, contact_id: ?int}>
     */
    public function resolve(Campaign $campaign): array
    {
        $included = $this->collect(
            (array) $campaign->target_lists,
            (array) $campaign->target_segments,
        );

        $excluded = $this->collect(
            (array) $campaign->exclude_lists,
            (array) $campaign->exclude_segments,
        );

        // KIVONÁS AZ UNIÓ UTÁN (lásd az osztály docblockját).
        foreach (array_keys($excluded) as $email) {
            unset($included[$email]);
        }

        // A RENDEZÉS a determinizmus miatt kell: ugyanaz a célzás ugyanabban a sorrendben
        // adja a címzetteket, tehát az A/B variáns-hozzárendelés is megismételhető.
        ksort($included);

        return array_map(
            static fn (string $email, ?int $contactId) => ['email' => $email, 'contact_id' => $contactId],
            array_keys($included),
            array_values($included),
        );
    }

    public function estimate(Campaign $campaign): int
    {
        return count($this->resolve($campaign));
    }

    /**
     * @param  list<mixed>  $listUuids
     * @param  list<mixed>  $segmentUuids
     * @return array<string, ?int> cím → kontakt-id
     */
    private function collect(array $listUuids, array $segmentUuids): array
    {
        $emails = [];

        foreach ($this->lists($listUuids) as $contact) {
            $emails[(string) $contact->email] = (int) $contact->id;
        }

        foreach ($this->segments($segmentUuids) as $contact) {
            // A LISTA-forrás nyer, ha ugyanaz a cím: mindegy, melyik id-t tartjuk meg (a
            // cím az azonosító), de a determinizmushoz kell, hogy ne váltakozzon.
            $emails[(string) $contact->email] ??= (int) $contact->id;
        }

        return $emails;
    }

    /**
     * @param  list<mixed>  $uuids
     * @return Collection<int, Contact>
     */
    private function lists(array $uuids): Collection
    {
        $uuids = $this->uuids($uuids);

        if ($uuids === []) {
            return collect();
        }

        $listIds = ContactList::query()->whereIn('uuid', $uuids)->pluck('id');

        if ($listIds->isEmpty()) {
            return collect();
        }

        return Contact::query()
            ->reachable()
            // A LEIRATKOZOTT TAGSÁG NEM TAGSÁG: a sor megmarad (a tény bizonyíték), de a
            // kampányba nem tartozik bele. Ugyanaz a szabály, mint a szegmens-motorban.
            ->whereHas('lists', fn (Builder $query) => $query
                ->whereIn('contact_lists.id', $listIds)
                ->where('contact_list_memberships.status', 'subscribed'))
            ->orderBy('id')
            ->get(['id', 'email']);
    }

    /**
     * @param  list<mixed>  $uuids
     * @return Collection<int, Contact>
     */
    private function segments(array $uuids): Collection
    {
        $uuids = $this->uuids($uuids);

        if ($uuids === []) {
            return collect();
        }

        $contacts = collect();

        foreach (Segment::query()->whereIn('uuid', $uuids)->get() as $segment) {
            // A SZEGMENS-MOTOR ugyanaz, amit a felület használ (B1): egy külön
            // kampány-oldali értelmezés azt jelentené, hogy a felületen mutatott
            // szegmens-tagság és a kampány címzettjei eltérnek.
            $contacts = $contacts->merge(
                $this->segments->query((array) $segment->definition)->get(['id', 'email'])
            );
        }

        return $contacts;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function uuids(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn ($value) => is_scalar($value) ? (string) $value : null,
            $values,
        )));
    }
}
