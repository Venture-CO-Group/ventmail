<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Controllers;

use App\Modules\Audience\Actions\SubmitForm;
use App\Modules\Audience\Data\FormSubmission;
use App\Modules\Audience\Models\Form;
use App\Modules\Domains\Models\Domain;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
    /** Egy szabad attribútum felső hossza. A publikus végpont nem tárhely. */
    private const MAX_ATTRIBUTE_LENGTH = 500;

    /** Az átvett attribútumok felső száma. */
    private const MAX_ATTRIBUTES = 30;

    /**
     * A visszatérési típus `Symfony`-szintű, mert a sikeres beküldés ÁTIRÁNYÍTHAT.
     *
     * `Illuminate\Http\Response`-szal a `redirect_url`-lel rendelkező űrlap MINDEN
     * beküldése 500-at adott (`TypeError`, mérve) — a kontakt és a consent létrejött, a
     * látogató hibaoldalt látott. Ez egyben megdöntötte a „a válasz mindig ugyanaz"
     * állítást is: létező slug → 500, nem létező → 200, vagyis SLUG-LÉTEZÉSI ORÁKULUM.
     */
    public function __invoke(
        Request $request,
        string $slug,
        SubmitForm $submit,
        WorkspaceContext $context,
    ): SymfonyResponse {
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

        $redirect = null;

        try {
            $submit($form, new FormSubmission(
                // MINDEN BEMENET HOSSZ- ÉS TÍPUS-KORLÁTOS.
                //
                // Hitelesítés nélküli végpont: a nem-skalár és a túl hosszú érték korábban
                // 500-at adott (`email` tömbként; 1000 karakteres `first_name` a
                // `varchar(255)`-en), a szabad attribútum hossza pedig KORLÁTLAN volt —
                // mérve 200 kB egy `contacts.attributes` sorban, 300 kérés/perc plafonnal.
                // Egy publikus űrlap így tárhely-amplifikációs eszköz lett volna.
                email: $this->text($request->input('email')) ?? '',
                firstName: $this->text($request->input('first_name')),
                lastName: $this->text($request->input('last_name')),
                attributes: $this->attributes($request, $form),
                honeypot: $this->text($request->input($form->honeypot_field)),
                // A KITÖLTÉSI IDŐ a kliens szava, tehát nem bizonyíték — de a bot
                // jellemzően nem is küldi, vagy nullát küld. A hiányzó érték NEM bukik el
                // a time-trapen: egy régi integráció különben csendben megszűnne működni.
                elapsedSeconds: $request->filled('_elapsed') ? (int) $request->input('_elapsed') : null,
                // A VALÓDI TCP-partner, nem a `request->ip()`.
                //
                // A `request->ip()` a `TRUSTED_PROXIES=*` miatt a KÉRŐ által beírt
                // `X-Forwarded-For`-ból jön — és ez az érték BIZONYÍTÉKBA kerül (a consent
                // `ip_hash`-e). Egy hamisítható IP bizonyítékként rosszabb, mint a semmi:
                // egy jogi vitában épp azt kérdőjelezi meg, amit igazolni akar.
                ip: (string) ($request->server('REMOTE_ADDR') ?? ''),
                userAgent: $request->userAgent(),
            ));

            // AZ ÁTIRÁNYÍTÁS ELLENŐRZÉSE IS A KONTEXTUSON BELÜL: a verifikált domainek
            // lekérdezése tenant-szűrt, tehát a `finally` utáni hívás a `WorkspaceScope`
            // fail-closed ágára futott volna (`1 = 0`), és MINDEN átirányítást elutasított
            // volna — csendben, „a köszönő oldalt adjuk" címen.
            $redirect = $this->isAllowedRedirect($form) ? (string) $form->redirect_url : null;
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        // AZ ÁTIRÁNYÍTÁS CÉLJA ENGEDÉLYEZETT DOMAINRE mutathat csak.
        //
        // A `redirect()->away()` a MI domainünkről visz tetszőleges https-címre: ellenőrzés
        // nélkül ez működő nyílt átirányító lenne — épp azon a domainen, aminek a jó híre a
        // kézbesíthetőségünk alapja. A cél ezért a tenant SAJÁT, verifikált domainje (vagy
        // annak aldomainje) kell legyen; minden más esetben a köszönő oldalt adjuk.
        if ($redirect !== null) {
            return redirect()->away($redirect);
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
                $value = $this->text($request->input($name), self::MAX_ATTRIBUTE_LENGTH);

                if ($value !== null && $value !== '') {
                    $allowed[$name] = $value;
                }
            }

            // A MEZŐK SZÁMA is korlátos: az űrlap definíciója a tenanté, de egy elrontott
            // (vagy rosszindulatú) definíció ne tudjon korlátlanul nagy JSON-t építeni.
            if (count($allowed) >= self::MAX_ATTRIBUTES) {
                break;
            }
        }

        return $allowed;
    }

    /**
     * A tenant SAJÁT, verifikált domainjére mutat-e az átirányítás?
     *
     * A verifikáció a bizalmi horgony: a `domains` táblában `verified` állapotú név azt
     * jelenti, hogy a tenant DNS-szinten bizonyította a tulajdonlást. Ami nem ilyen
     * domainre megy, azt nem irányítjuk — nem azért, mert biztosan rosszindulatú, hanem
     * mert nem tudjuk megállapítani, hogy nem az.
     */
    private function isAllowedRedirect(Form $form): bool
    {
        $url = (string) ($form->redirect_url ?? '');

        if ($url === '' || ! str_starts_with($url, 'https://')) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach (Domain::query()->where('status', 'verified')->pluck('name') as $name) {
            $name = strtolower((string) $name);

            if ($host === $name || str_ends_with($host, '.'.$name)) {
                return true;
            }
        }

        return false;
    }

    /** A NEM SZTRING elem `null`, a túl hosszú CSONKOLVA — nem 500. */
    private function text(mixed $value, int $limit = 200): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        return mb_substr(trim((string) $value), 0, $limit);
    }

    private function done(): Response
    {
        return response(view('audience.form-submitted')->render(), 200);
    }
}
