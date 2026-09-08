<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Controllers;

use App\Modules\Audience\Actions\SubmitForm;
use App\Modules\Audience\Data\FormSubmission;
use App\Modules\Audience\Models\Form;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `POST /f/{slug}` — publikus űrlap-beküldés (54. kritérium).
 *
 * Hitelesítés nincs, és nem is lehet: ezt az űrlapot az ügyfél weboldala küldi be, névtelen
 * látogatóktól. A védelem ezért nem az azonosítás, hanem:
 *
 *  - a `slug` mint egyetlen azonosító (a workspace-t az űrlap adja, nem a kérés),
 *  - a honeypot + time-trap (a beküldés feldolgozásának elválaszthatatlan része),
 *  - rate-limit,
 *  - és az, hogy a beküldés MAXIMUM egy `pending` consentet hoz létre. Jogalapot nem.
 *
 * A VÁLASZ MINDIG UGYANAZ — botra, ismeretlen slugra, érvényes beküldésre is. A különbség
 * orákulum lenne arra, hogy melyik űrlap létezik, és a bot-szűrés visszajelzése a következő
 * próbálkozást tanítaná meg a csapda kikerülésére.
 */
final class PublicFormController
{
    public function __invoke(
        Request $request,
        string $slug,
        SubmitForm $submit,
        WorkspaceContext $context,
    ): Response {
        // A `withoutGlobalScopes` SZÁNDÉKOS és dokumentált: a beküldés névtelen, tehát
        // tenant-kontextus nélkül érkezik — épp ez a lekérdezés állapítja meg, melyik
        // tenanté az űrlap. Egyetlen, `slug` + `active` egyezésre korlátozott lekérdezés,
        // `limit(2)`-vel az egyértelműség őrzésére (ugyanaz a minta, mint a
        // `VerifiedInboundResolver`-ben és az MTA-STS végponton).
        $matches = Form::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('active', true)
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            return $this->done();
        }

        $form = $matches->first();

        $workspace = Workspace::query()->whereKey($form->workspace_id)->first();

        if ($workspace === null) {
            return $this->done();
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            $submit($form, new FormSubmission(
                email: (string) $request->input('email', ''),
                firstName: $this->text($request->input('first_name')),
                lastName: $this->text($request->input('last_name')),
                attributes: $this->attributes($request, $form),
                honeypot: $this->text($request->input($form->honeypot_field)),
                // A KITÖLTÉSI IDŐ a kliens szava, tehát nem bizonyíték — de a bot
                // jellemzően nem is küldi, vagy nullát küld. A hiányzó érték NEM bukik el
                // a time-trapen: egy régi integráció különben csendben megszűnne működni.
                elapsedSeconds: $request->filled('_elapsed') ? (int) $request->input('_elapsed') : null,
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            ));
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        if (($form->redirect_url ?? '') !== '' && str_starts_with((string) $form->redirect_url, 'https://')) {
            return redirect()->away((string) $form->redirect_url);
        }

        return $this->done();
    }

    /**
     * A SZABAD attribútumok — CSAK az űrlapon definiált mezők.
     *
     * Ha bármit átvennénk, a beküldő tetszőleges kulcsot írhatna a kontakt
     * attribútumaiba — és a szegmens-motor ezekre szűr, vagyis egy idegen látogató
     * befolyásolhatná, ki kap kampányt.
     *
     * @return array<string, mixed>
     */
    private function attributes(Request $request, Form $form): array
    {
        $allowed = [];

        foreach ((array) $form->fields as $field) {
            $name = is_array($field) ? (string) ($field['name'] ?? '') : (string) $field;

            if ($name !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) === 1 && $request->filled($name)) {
                $value = $request->input($name);

                if (is_scalar($value)) {
                    $allowed[$name] = (string) $value;
                }
            }
        }

        return $allowed;
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private function done(): Response
    {
        return response(view('audience.form-submitted')->render(), 200);
    }
}
