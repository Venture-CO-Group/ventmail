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
 * ## Miért ír egy GET
 *
 * A leiratkozásnál (`/u/`) a GET szándékosan NEM ír: ott a védelmi átjárók (SafeLinks,
 * vírusszűrő proxy) link-előtöltése csendben leiratkoztatná azt, aki nem kattintott — és a
 * kár VISSZAVONHATATLAN (a tenant API-ból nem veheti le a sort).
 *
 * Itt a mérleg fordítva áll. A megerősítés a felhasználó SZÁNDÉKÁT teljesíti, a kára pedig
 * kicsi és visszafordítható: ha egy előtöltés erősíti meg, a címzett kap egy levelet, amiről
 * egy kattintással leiratkozik. Egy megerősítő OLDAL viszont a beiratkozók egy részét
 * elveszítené — és a golden rule 4 szempontjából a nem-megerősített consent a rosszabb
 * kimenet, mert onnantól a lista jogalap nélküli.
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

    public function __invoke(Request $request, string $token, WorkspaceContext $context): Response
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
