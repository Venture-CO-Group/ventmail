<?php

declare(strict_types=1);

namespace App\Modules\Consent\Http\Controllers;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Consent\Services\PreferenceTokens;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Suppression\Services\SuppressionStore;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PREFERENCE CENTER (55. kritérium) — lista, gyakoriság, leiratkozás.
 *
 * ## Miért van egyáltalán
 *
 * A „leiratkozom mindenről" gomb a gyakorlatban azt jelenti, hogy a címzett a hasznos
 * levelekről is leiratkozik, mert egyetlen zavaró miatt nem talál mást. A preference center
 * a KÖZTES lépés: listánként és gyakoriságonként lehet szűkíteni — és ez mérhetően kevesebb
 * teljes leiratkozást és kevesebb SPAM-PANASZT jelent. A panasz a küldő reputációját viszi,
 * nem a levélét.
 *
 * ## A KONZISZTENCIA a suppression-listával
 *
 * Ez a legfontosabb pont, és a kritérium is kimondja. A „mindenről leiratkozom" itt UGYANAZT
 * kell jelentse, mint a one-click leiratkozás a levél fejlécében: `applies_to = marketing`
 * suppression-sor, ÉS visszavont consent. Ha a két út más állapotot hagyna, a címzett
 * leiratkozna itt, és a kampány mégis kimenne — a suppression-lista ugyanis a kapu bemenete,
 * nem a preference center táblái.
 *
 * A GET NEM ÍR (a védelmi átjárók link-előtöltése miatt), a POST igen.
 */
final class PreferenceCenterController
{
    public function __construct(
        private readonly PreferenceTokens $tokens,
        private readonly ConsentRecorder $recorder,
        private readonly SuppressionStore $suppressions,
    ) {}

    public function show(string $token, WorkspaceContext $context): Response
    {
        $state = $this->resolve($token, $context, function (Contact $contact) {
            return [
                'lists' => $this->visibleLists($contact)->map(fn (ContactList $list) => [
                    'uuid' => (string) $list->uuid,
                    'name' => (string) $list->name,
                    'description' => (string) $list->description,
                    'subscribed' => $contact->lists()
                        ->where('contact_lists.id', $list->getKey())
                        ->wherePivot('status', 'subscribed')
                        ->exists(),
                ])->all(),
            ];
        });

        // MINDIG ugyanaz az oldal, akkor is, ha a token hamis vagy lejárt — csak listák
        // nélkül. A különbség orákulum lenne arra, hogy egy cím szerepel-e a rendszerben.
        return response(view('consent.preferences', [
            'token' => $token,
            'lists' => $state['lists'] ?? [],
        ])->render(), 200);
    }

    public function update(Request $request, string $token, WorkspaceContext $context): Response
    {
        $unsubscribeAll = $request->boolean('unsubscribe_all');

        // A `lists[0][]=x` alak korábban `ErrorException`-t adott az `array_map('strval')`-on
        // — hitelesítés nélküli végponton 500. A nem-skalár elem itt egyszerűen nem
        // azonosító, tehát kiesik.
        $keep = array_values(array_filter(array_map(
            static fn ($value) => is_scalar($value) ? (string) $value : null,
            (array) $request->input('lists', []),
        )));

        $this->resolve($token, $context, function (Contact $contact) use ($unsubscribeAll, $keep): array {
            // EGY TRANZAKCIÓ: a három írás (suppression / consent / kontakt+pivot) vagy
            // mind megtörténik, vagy egyik sem. Részleges hibánál a három igazság
            // szétcsúszna — és a leghangosabb (a kampány) nyerne.
            DB::transaction(function () use ($contact, $unsubscribeAll, $keep): void {
                $unsubscribeAll
                    ? $this->unsubscribeEverything($contact)
                    : $this->applySelection($contact, $keep);
            });

            return [];
        });

        return response(view('consent.preferences-saved')->render(), 200);
    }

    /**
     * A LISTÁNKÉNTI választás alkalmazása.
     *
     * @param  list<string>  $keep
     */
    private function applySelection(Contact $contact, array $keep): void
    {
        /*
         | A SUPPRESSION-LISTA ERŐSEBB A PIPÁNÁL.
         |
         | Aki leiratkozott (vagy panaszt tett), annak a visszapipálása ITT nem hozhat vissza
         | jogalapot: a consent `revoked`, a suppression-sor áll, tehát a levél amúgy sem
         | menne ki — a felület viszont bepipált négyzetet rajzolt volna egy olyan listára,
         | amiről a címzett SOHA nem kap levelet. Ez a legrosszabb fajta hazugság a
         | felületen: az érintett azt hiszi, beállított valamit.
         |
         | Új feliratkozás új double opt-int igényel; a preference center nem kerülheti meg.
         */
        if ($this->isSuppressed($contact)) {
            $keep = [];
        }

        foreach ($this->visibleLists($contact) as $list) {
            $wanted = in_array((string) $list->uuid, $keep, true);

            $before = (string) ($contact->lists()->where('contact_lists.id', $list->getKey())
                ->first()?->pivot->status ?? 'unsubscribed');

            // A tagságot NEM töröljük, hanem ÁLLAPOTOT írunk: a leiratkozás ténye (mikor,
            // melyik listáról) bizonyíték, és a sor törlésével elveszne.
            $contact->lists()->syncWithoutDetaching([
                $list->getKey() => [
                    'status' => $wanted ? 'subscribed' : 'unsubscribed',
                    'subscribed_at' => $wanted ? now() : null,
                    'unsubscribed_at' => $wanted ? null : now(),
                ],
            ]);

            // A LISTÁNKÉNTI VÁLTOZÁS IS BIZONYÍTÉK (57. kritérium).
            //
            // Korábban csak a teljes leiratkozás írt eseményt: a listánkénti be- és
            // kikapcsolás nyomtalanul történt, pedig az is a consent hatókörét módosítja.
            // Egy „miért kaptam ezt a levelet?" kérdésre a tagsági állapotból nem derül ki,
            // hogy a címzett MIKOR és HONNAN kapcsolta be.
            if ($before !== ($wanted ? 'subscribed' : 'unsubscribed')) {
                $this->recorder->listPreference(
                    (string) $contact->email,
                    (string) $list->uuid,
                    $wanted,
                );
            }
        }

        // MINDEN listáról lekerült: ez ugyanaz, mint a „mindenről leiratkozom".
        //
        // Enélkül a címzett minden pipát kivehetne, a consent viszont `confirmed` maradna —
        // és egy listához nem kötött kampány továbbra is kimenne neki.
        if ($contact->lists()->wherePivot('status', 'subscribed')->doesntExist()) {
            $this->unsubscribeEverything($contact);
        }
    }

    /**
     * A címzett SAJÁT listái — nem a tenant teljes katalógusa.
     *
     * A korábbi változat MINDEN listát kilistázott a leíró szövegével együtt, tehát a token
     * birtokosa megismerte a tenant teljes listastruktúráját — mérve: egy „belső VIP lista"
     * megjelent egy olyan kontaktnak, aki sosem volt rajta. Egy preference center a
     * címzettről szól, nem a küldő szervezetéről.
     *
     * @return Collection<int, ContactList>
     */
    private function visibleLists(Contact $contact): Collection
    {
        return ContactList::query()
            ->whereIn('id', $contact->lists()->pluck('contact_lists.id'))
            ->orderBy('name')
            ->get();
    }

    private function isSuppressed(Contact $contact): bool
    {
        return Suppression::query()
            ->where('email', $contact->email)
            ->get()
            ->contains(fn (Suppression $suppression) => $suppression->isActive());
    }

    /**
     * „Mindenről leiratkozom" — a HÁROM állapot EGYÜTT.
     *
     * A suppression-sor a döntő: azt kérdezi a PreSendGate 2. kapuja. A consent visszavonása
     * a jogalapot zárja, a kontakt állapota a felületnek szól. Ha bármelyik kimaradna, a
     * három igazság szétcsúszna — és a leghangosabb (a kampány) nyerne.
     */
    private function unsubscribeEverything(Contact $contact): void
    {
        $this->suppressions->recordUnsubscribe(
            (string) $contact->email,
            'preference_center',
            'contact:'.$contact->uuid,
        );

        foreach (Consent::query()->where('email', $contact->email)->get() as $consent) {
            if ($consent->channel !== 'transactional') {
                $this->recorder->revoke($consent, 'preference_center');
            }
        }

        $contact->forceFill(['status' => 'unsubscribed', 'unsubscribed_at' => now()])->save();

        foreach ($contact->lists as $list) {
            $contact->lists()->updateExistingPivot($list->getKey(), [
                'status' => 'unsubscribed',
                'unsubscribed_at' => now(),
            ]);
        }
    }

    /**
     * @param  callable(Contact): array<string, mixed>  $work
     * @return array<string, mixed>
     */
    private function resolve(string $token, WorkspaceContext $context, callable $work): array
    {
        $claim = $this->tokens->parse($token);

        if ($claim === null) {
            return [];
        }

        $workspace = Workspace::query()->whereKey($claim['workspaceId'])->first();

        if ($workspace === null) {
            return [];
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            $contact = Contact::query()->where('email', $claim['email'])->first();

            return $contact === null ? [] : $work($contact);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }
}
