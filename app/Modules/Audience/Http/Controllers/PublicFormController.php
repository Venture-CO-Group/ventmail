<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Controllers;

use App\Modules\Audience\Actions\SubmitForm;
use App\Modules\Audience\Data\FormSubmission;
use App\Modules\Audience\Models\Form;
use App\Modules\Audience\Services\FormRenderTokens;
use App\Modules\Audience\Services\Import\AttributeCoercer;
use App\Modules\Domains\Models\Domain;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\ClientAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
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
            return $this->done(null);
        }

        $form = $matches->first();

        $workspace = Workspace::query()->whereKey($form->workspace_id)->first();

        if ($workspace === null) {
            return $this->done(null);
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        $redirect = null;

        try {
            // v1.1 · KÖTELEZŐ mező hiánya: nincs beiratkozás, de a válasz UGYANAZ (a hosztolt
            // oldal `required` attribútuma a valódi kitöltőt előbb megállítja).
            // A HOZZÁJÁRULÁS jelölőnégyzete szerveroldalon is kötelező (review S3): a bejelölés
            // nélküli beküldés nem teremthet függő consentet — a válasz ugyanaz.
            if (! $this->requiredPresent($request, $form) || ! $request->boolean('consent')) {
                return $this->done($form);
            }

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
                elapsedSeconds: $this->elapsed($request, $form),
                // A VALÓDI TCP-partner, nem a `request->ip()`.
                //
                // A `request->ip()` a `TRUSTED_PROXIES=*` miatt a KÉRŐ által beírt
                // `X-Forwarded-For`-ból jön — és ez az érték BIZONYÍTÉKBA kerül (a consent
                // `ip_hash`-e). Egy hamisítható IP bizonyítékként rosszabb, mint a semmi:
                // egy jogi vitában épp azt kérdőjelezi meg, amit igazolni akar.
                // Az edge mögött a TCP-partner a proxy: a valódi cím a `ClientAddress`-ből jön,
                // ami a továbbított fejlécet CSAK a szűkített edge-listától fogadja el.
                ip: ClientAddress::of($request),
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

        return $this->done($form);
    }

    /**
     * A HOSZTOLT ŰRLAP-OLDAL (v1.1 · 5. modul) — link vagy iframe.
     *
     * Csak AKTÍV űrlap jelenik meg (az inaktív és az ismeretlen 404 — a slug a publikus link
     * része, tehát a létezése nem titok). A mezők az űrlap-építő definíciójából jönnek; a
     * honeypot és a renderelési token (`_rt`, szkript nélküli time-trap) minden kiszolgáláskor
     * friss.
     */
    public function show(string $slug, FormRenderTokens $tokens, WorkspaceContext $context): Response
    {
        $matches = Form::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('active', true)
            ->limit(2)
            ->get();

        $form = $matches->count() === 1 ? $matches->first() : null;
        $workspace = $form === null ? null : Workspace::query()->whereKey($form->workspace_id)->first();

        abort_if($form === null || $workspace === null, 404);

        $page = (array) $form->page;

        return response(view('audience.form-page', [
            'workspaceName' => (string) $workspace->name,
            'title' => (string) (($page['title'] ?? '') !== '' ? $page['title'] : $form->name),
            'intro' => (string) ($page['intro'] ?? ''),
            'button' => (string) (($page['button'] ?? '') !== '' ? $page['button'] : __('studio.hosted.subscribe')),
            'consentText' => (string) $form->consent_text,
            'fields' => $this->renderFields($form),
            'honeypot' => (string) $form->honeypot_field,
            'renderToken' => $tokens->issue((string) $form->uuid),
            'action' => route('forms.submit', ['slug' => $form->slug]),
        ])->render(), 200);
    }

    /**
     * A megjelenítendő mezők (a kötelező e-mail-cím mellett).
     *
     * @return list<array{name: string, label: string, input: string, required: bool}>
     */
    private function renderFields(Form $form): array
    {
        $rows = [];

        foreach ((array) $form->fields as $field) {
            if (! is_array($field) || ! is_string($field['name'] ?? null)) {
                continue;
            }

            $name = (string) $field['name'];

            $rows[] = [
                'name' => $name,
                'label' => (string) (($field['label'] ?? '') !== '' ? $field['label'] : match ($name) {
                    'first_name' => __('studio.hosted.firstName'),
                    'last_name' => __('studio.hosted.lastName'),
                    default => $name,
                }),
                'input' => match ((string) ($field['type'] ?? 'text')) {
                    'number' => 'number',
                    'date' => 'date',
                    default => 'text',
                },
                'required' => (bool) ($field['required'] ?? false),
            ];
        }

        return $rows;
    }

    /** A kötelezőnek jelölt mezők mind kitöltöttek-e? */
    private function requiredPresent(Request $request, Form $form): bool
    {
        foreach ((array) $form->fields as $field) {
            if (is_array($field) && ($field['required'] ?? false) && is_string($field['name'] ?? null)) {
                $value = $request->input($field['name']);

                if (! is_scalar($value) || trim((string) $value) === '') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A KITÖLTÉSI IDŐ: a hosztolt oldal aláírt renderelési tokenjéből (`_rt`), különben a
     * beágyazott kód `_elapsed` mezőjéből.
     *
     * A MEGLÉVŐ, de érvénytelen token (hamis, más űrlapé, lejárt) NULLA másodperc: bukik a
     * time-trapen — a hamisított token nem kaphat „hiányzó mérés" felmentést.
     */
    private function elapsed(Request $request, Form $form): ?int
    {
        if ($request->filled('_rt')) {
            $token = $request->input('_rt');

            if (! is_string($token)) {
                return 0;
            }

            $elapsed = app(FormRenderTokens::class)->elapsed($token, (string) $form->uuid);

            // EGYSZER HASZNÁLHATÓ (review S1): egy megszerzett token egy napig nem lehet
            // korlátlan beküldés-jegy. Az ismételt token nulla másodperc — bukik.
            if ($elapsed === null || ! Cache::add('form-rt:'.hash('sha256', $token), 1, 86400)) {
                return 0;
            }

            return $elapsed;
        }

        // A KÖTELEZŐ MÉRÉSŰ űrlapon (review S1) a kliens szava nem számít: token nélkül bot.
        if ($form->require_timing) {
            return 0;
        }

        return $request->filled('_elapsed') ? (int) $request->input('_elapsed') : null;
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

            // A NÉV-mezők a kontakt saját oszlopai (v1.1 űrlap-építő), nem attribútumok.
            if (in_array($name, ['email', 'first_name', 'last_name'], true)) {
                continue;
            }

            if ($name !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) === 1 && $request->filled($name)) {
                $value = $this->text($request->input($name), self::MAX_ATTRIBUTE_LENGTH);

                // v1.1 · TÍPUSOS egyéni mező: a típusba nem illő érték kimarad (nem ír szemetet
                // a szám- vagy dátummezőbe, amire a szegmens-motor szűr).
                if ($value !== null && $value !== '' && is_array($field) && in_array($field['type'] ?? 'text', ['number', 'date', 'boolean'], true)) {
                    $value = app(AttributeCoercer::class)->coerce((string) $field['type'], $value);
                }

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

        // A VISSZAPERJEL és a USERINFO (review N4): a böngészők a `\`-t `/`-ként olvassák,
        // a `user@host` pedig a host-ellenőrzést zavarja — egyik sem kell egy köszönő oldalhoz.
        if (str_contains($url, '\\') || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
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

    /**
     * A KÖSZÖNŐ OLDAL — űrlaponként az űrlap saját szövegével, ismeretlen űrlapnál általános.
     * Botra és érvényes beküldésre UGYANAZ (ugyanannak az űrlapnak).
     */
    private function done(?Form $form): Response
    {
        $page = $form === null ? [] : (array) $form->page;

        return response(view('audience.form-submitted', [
            'title' => ($page['successTitle'] ?? '') !== '' ? (string) $page['successTitle'] : null,
            'message' => ($page['successMessage'] ?? '') !== '' ? (string) $page['successMessage'] : null,
        ])->render(), 200);
    }
}
