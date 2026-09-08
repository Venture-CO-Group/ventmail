<?php

declare(strict_types=1);

namespace App\Modules\Consent\Http\Controllers;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Services\ConfirmationTokens;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /c/{token}` — a double opt-in MEGERŐSÍTÉSE (50. kritérium).
 *
 * ## A GET NEM ÍR — és ezt az érvet egyszer már elrontottam
 *
 * Első változatban a GET erősített meg, azzal az érvvel, hogy „a megerősítés a felhasználó
 * szándékát teljesíti, a kára kicsi és visszafordítható". A review megmérte, mi történik
 * valójában: egy `Microsoft-SafeLinks-Scanner` user-agenttel érkező, EMBERI INTERAKCIÓ
 * NÉLKÜLI kérés `confirmed` jogalapot teremtett — és a bizonyítékba a SZKENNER IP-je és
 * user-agentje került az érintett hozzájárulásaként.
 *
 * A hiba nem a konverziós mérlegben volt, hanem abban, hogy egy JOGI kérdést konverziós
 * érvvel döntöttem el. A rögzített bizonyíték így nem csak hiányos, hanem AKTÍVAN
 * FÉLREVEZETŐ: azt állítja, hogy az érintett hozzájárult, miközben egy levélátjáró
 * kérte le a linket. Minden szkennelő átjáró mögötti címzettnél a double opt-in de facto
 * single opt-inné vált — vagyis pont a golden rule 4 sérült, aminek a védelmére hivatkoztam.
 *
 * Ugyanaz a döntés, mint a `/u/` végponton, ugyanabból az okból: a GET egygombos megerősítő
 * oldalt mutat, az ÍRÁS a POST. Egy kattintás az ára, és csak azon az úton, amit a
 * link-előtöltés érint.
 *
 * A HITELESÍTÉS a token: titkosított, cél-elválasztott, LEJÁRÓ. A tenant-kontextus is belőle
 * jön, és MINDIG ugyanaz a válasz — érvényes, hamis és lejárt tokenre is. A különbség
 * orákulum lenne arra, hogy egy cím szerepel-e a rendszerben.
 */
final class ConfirmSubscriptionController
{
    public function __construct(
        private readonly ConfirmationTokens $tokens,
        private readonly ConsentRecorder $recorder,
    ) {}

    /** A LINK: megerősítő oldal, írás nélkül. */
    public function show(string $token): Response
    {
        return response(view('consent.confirm', ['token' => $token])->render(), 200);
    }

    /** A GOMB: itt történik a megerősítés. */
    public function store(Request $request, string $token, WorkspaceContext $context): Response
    {
        $this->confirm($request, $token, $context);

        return response(view('consent.confirmed')->render(), 200);
    }

    private function confirm(Request $request, string $token, WorkspaceContext $context): void
    {
        $claim = $this->tokens->parse($token);

        if ($claim === null) {
            return;
        }

        $workspace = Workspace::query()->whereKey($claim['workspaceId'])->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            $consent = Consent::query()
                ->where('email', $claim['email'])
                ->where('channel', $claim['channel'])
                ->first();

            // Nincs mit megerősíteni: a kérés sosem született meg, vagy törölték. NEM
            // hozunk létre újat — az azt jelentené, hogy egy tokennel jogalapot lehet
            // gyártani kérés nélkül.
            if ($consent === null) {
                return;
            }

            $this->recorder->confirm($consent, $request->ip(), $request->userAgent());

            $contact = Contact::query()->where('email', $claim['email'])->first();

            if ($contact === null) {
                return;
            }

            $contact->forceFill([
                'status' => 'subscribed',
                'subscribed_at' => $contact->subscribed_at ?? now(),
                'unsubscribed_at' => null,
            ])->save();

            // A LISTA-tagság is megerősítődik — de csak az, amelyikre a token szól: egy
            // űrlap megerősítése nem iratkoztat fel minden listára.
            if ($claim['listUuid'] !== null) {
                $list = ContactList::query()->where('uuid', $claim['listUuid'])->first();

                if ($list !== null) {
                    $contact->lists()->syncWithoutDetaching([
                        $list->getKey() => ['status' => 'subscribed', 'subscribed_at' => now()],
                    ]);
                }
            }
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }
}
