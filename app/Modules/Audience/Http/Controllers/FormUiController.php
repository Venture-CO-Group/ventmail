<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Controllers;

use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Form;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BEIRATKOZÓ ŰRLAPOK képernyője (54. kritérium).
 *
 * ## Amit ez a képernyő valójában ad: a BEÁGYAZHATÓ KÓD
 *
 * Az űrlapot az ügyfél SAJÁT weboldala tartalmazza — mi csak a `POST /f/{slug}` végpontot
 * adjuk. A tenant tehát két dolgot NEM tud kitalálni: a slugot és a HONEYPOT MEZŐ NEVÉT.
 * Az utóbbi űrlaponként generált (ezért nem ismeri fel egy általános bot), és ha a beágyazott
 * kódból hiányzik, a csapda egyszerűen nem működik — csendben.
 *
 * Ezért ad ez a képernyő KÉSZ HTML-t, nem csak mezőneveket: a rejtett mező CSS-ét, a
 * time-trap időbélyegét és a slugot együtt. Egy „töltsd ki magad" leírás mellett a
 * bot-védelem fele hiányozna a valódi űrlapokból.
 *
 * ## A honeypot nevének megmutatása nem gyengítés
 *
 * A tenantnak látnia KELL, különben nem tud űrlapot építeni. A csapda nem titkosságon
 * alapul, hanem azon, hogy a bot nem tudja MEGKÜLÖNBÖZTETNI a valódi mezőktől — a saját
 * oldalán a név egyébként is nyilvános a HTML-forrásban.
 */
final class FormUiController
{
    public function index(): Response
    {
        Gate::authorize('audience.manage');

        $forms = Form::query()->with('list')->orderByDesc('id')->get();

        return Inertia::render('Audience/Forms', [
            'endpointBase' => rtrim((string) config('app.url'), '/').'/f/',
            'lists' => ContactList::query()->orderBy('name')->get()->map(fn (ContactList $list) => [
                'uuid' => (string) $list->uuid,
                'name' => (string) $list->name,
            ])->values()->all(),

            'forms' => $forms->map(fn (Form $form) => [
                'uuid' => (string) $form->uuid,
                'name' => (string) $form->name,
                'slug' => (string) $form->slug,
                'active' => (bool) $form->active,
                'listName' => $form->list?->name,
                'listUuid' => $form->list === null ? null : (string) $form->list->uuid,
                'honeypotField' => (string) $form->honeypot_field,
                'minSeconds' => (int) $form->min_seconds,
                'policyVersion' => $form->policy_version,
                'consentText' => $form->consent_text,
                'redirectUrl' => $form->redirect_url,
                // A CÉLLISTA MÉRETE — és SZÁNDÉKOSAN nem „ennyien iratkoztak be ezen az
                // űrlapon". Olyan szám ma nincs: a consent forrása `form`, de nem hordozza,
                // MELYIK űrlapé. Egy összes-űrlapos szám minden sor mellé ugyanazt írná ki,
                // és a felhasználó azt hinné, hogy űrlaponkénti adatot lát.
                'listContacts' => $form->list === null ? null : $form->list->contacts()->count(),
                'snippet' => $this->snippet($form),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $validated = $this->validated($request);

        Form::create($validated);

        return back()->with('success', __('ventmail.forms.created'));
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $form = Form::query()->where('uuid', $uuid)->firstOrFail();

        $form->fill($this->validated($request))->save();

        return back()->with('success', __('ventmail.forms.updated'));
    }

    /**
     * BE- ÉS KIKAPCSOLÁS — nem törlés.
     *
     * Az inaktív űrlap végpontja tovább válaszol (ugyanazt, mint mindig), de nem hoz létre
     * consentet: az ügyfél oldalán maradt régi űrlap így nem hibázik, csak nem gyűjt. A
     * törlés a bizonyíték-láncot rontaná: a `consent_events` a forrás-űrlapra hivatkozik.
     */
    public function toggle(string $uuid): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $form = Form::query()->where('uuid', $uuid)->firstOrFail();

        $form->active = ! $form->active;
        $form->save();

        return back()->with('success', __('ventmail.forms.updated'));
    }

    /**
     * A BEÁGYAZHATÓ HTML — a SZERVEREN összeállítva.
     *
     * Nem a böngészőben, két okból. Egy: ez SZERZŐDÉS a publikus végponttal (a mezőnevek
     * — `email`, `_elapsed`, a generált honeypot — a `PublicFormController` bemenetei), és
     * a szerződést teszt őrzi, nem egy sablon-kifejezés. Kettő: a kód beégetett CSS-t
     * tartalmaz (`left:-5000px`), ami az ÜGYFÉL oldalán fut, a mi design-tokenjeink nélkül
     * — a frontend-kapunk (helyesen) minden beégetett méretet elutasít, és ezt a kivételt
     * nem a kapu fellazításával kell megoldani.
     *
     * A rejtett mező NEM `type="hidden"`: azt a botok kiszűrik. Az ember viszont a
     * képernyőn kívülre tolt mezőt sem látja.
     */
    private function snippet(Form $form): string
    {
        $action = rtrim((string) config('app.url'), '/').'/f/'.$form->slug;
        $id = 'vm-'.$form->slug;
        $consent = e((string) $form->consent_text);

        return implode("\n", [
            '<form id="'.$id.'" method="post" action="'.$action.'">',
            '  <input type="email" name="email" required>',
            '  <input type="text" name="first_name">',
            '  <div style="position:absolute;left:-5000px" aria-hidden="true">',
            '    <input type="text" name="'.e((string) $form->honeypot_field).'" tabindex="-1" autocomplete="off">',
            '  </div>',
            // A TIME-TRAP mérése: a szerver a hiányzó `_elapsed`-et ÁTENGEDI (egy régi
            // integráció nem szűnhet meg csendben működni), tehát a bot-védelem fele
            // ezen a mezőn múlik — a beágyazott kódnak muszáj hoznia.
            '  <input type="hidden" name="_elapsed" value="0">',
            '  <label><input type="checkbox" name="consent" required> '.$consent.'</label>',
            '  <button type="submit">'.__('ventmail.forms.submitLabel').'</button>',
            '</form>',
            '<script>',
            '(function () {',
            '  var f = document.getElementById("'.$id.'");',
            '  var start = Date.now();',
            '  f.addEventListener("submit", function () {',
            '    f._elapsed.value = Math.round((Date.now() - start) / 1000);',
            '  });',
            '})();',
            '</script>',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'listUuid' => ['nullable', 'string', 'uuid'],
            // A KÜSZÖB nem lehet 0: a time-trap kikapcsolása a bot-védelem felét venné el,
            // és a felületen ez egy ártalmatlan „0"-nak látszana.
            'minSeconds' => ['required', 'integer', 'min:1', 'max:120'],
            'policyVersion' => ['nullable', 'string', 'max:40'],
            // A HOZZÁJÁRULÁS SZÖVEGE a bizonyíték része: ez kerül a `consent_events`-be,
            // mert egy hatósági kérdésre nem a „consent=true", hanem a LÁTOTT SZÖVEG a válasz.
            'consentText' => ['required', 'string', 'max:2000'],
            'redirectUrl' => ['nullable', 'string', 'url', 'max:2048', 'starts_with:https://'],
        ]);

        $list = $validated['listUuid'] ?? null;

        return [
            'name' => $validated['name'],
            // A LISTA a global scope-on át keresődik: egy idegen workspace lista-uuid-ja
            // így nem található, és `null`-lá esik — nem köt össze két tenantot.
            'contact_list_id' => $list === null ? null : ContactList::query()->where('uuid', $list)->value('id'),
            'min_seconds' => (int) $validated['minSeconds'],
            'policy_version' => $validated['policyVersion'] ?? null,
            'consent_text' => $validated['consentText'],
            'redirect_url' => $validated['redirectUrl'] ?? null,
        ];
    }
}
