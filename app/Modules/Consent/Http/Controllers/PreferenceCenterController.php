<?php

declare(strict_types=1);

namespace App\Modules\Consent\Http\Controllers;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Consent\Services\PreferenceTokens;
use App\Modules\Suppression\Services\SuppressionStore;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
                'lists' => ContactList::query()->orderBy('name')->get()->map(fn (ContactList $list) => [
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
        $keep = array_map('strval', (array) $request->input('lists', []));

        $this->resolve($token, $context, function (Contact $contact) use ($unsubscribeAll, $keep): array {
            if ($unsubscribeAll) {
                $this->unsubscribeEverything($contact);

                return [];
            }

            foreach (ContactList::query()->get() as $list) {
                $wanted = in_array((string) $list->uuid, $keep, true);

                // A tagságot NEM töröljük, hanem ÁLLAPOTOT írunk: a leiratkozás ténye
                // (mikor, melyik listáról) bizonyíték, és a sor törlésével elveszne.
                $contact->lists()->syncWithoutDetaching([
                    $list->getKey() => [
                        'status' => $wanted ? 'subscribed' : 'unsubscribed',
                        'subscribed_at' => $wanted ? now() : null,
                        'unsubscribed_at' => $wanted ? null : now(),
                    ],
                ]);
            }

            // MINDEN listáról lekerült: ez ugyanaz, mint a „mindenről leiratkozom".
            //
            // Enélkül a címzett minden pipát kivehetne, a consent viszont `confirmed`
            // maradna — és egy listához nem kötött kampány továbbra is kimenne neki.
            if ($contact->lists()->wherePivot('status', 'subscribed')->doesntExist()) {
                $this->unsubscribeEverything($contact);
            }

            return [];
        });

        return response(view('consent.preferences-saved')->render(), 200);
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
