<?php

declare(strict_types=1);

namespace App\Modules\Audience\Actions;

use App\Modules\Audience\Data\FormSubmission;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\Form;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Consent\Services\ConfirmationTokens;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Services\JourneyTrigger;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Contracts\AddressRiskScorer;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Suppression\Models\Suppression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ŰRLAP-BEIRATKOZÁS (50., 54. kritérium) — a double opt-in első lépése.
 *
 * ## A bot-szűrés két rétege, és miért nem CAPTCHA
 *
 * A CAPTCHA a valódi beiratkozók egy részét elveszíti — mobilon különösen. A honeypot +
 * time-trap páros a látogató számára LÁTHATATLAN, és a bot-forgalom nagy részét kifogja:
 *
 *  - **honeypot**: egy CSS-sel elrejtett mező, aminek a NEVE űrlaponként más. Az ember nem
 *    látja, tehát nem tölti ki; a bot minden mezőt kitölt. A generált név azért kell, mert
 *    egy állandó, kitalálható név (`website`) néhány hét alatt bekerül a bot-keretrendszerek
 *    kihagyási listájába;
 *  - **time-trap**: az ember kitöltése másodpercekben mérhető, a boté milliszekundumokban. A
 *    küszöb alatti beküldés eldobva.
 *
 * A BOTOT CSENDBEN dobjuk el, és a válasz UGYANAZ, mint a sikeres beküldésnél. Ha
 * megmondanánk, hogy „bot vagy", a következő próbálkozás megkerülné a csapdát — a
 * visszajelzés itt a támadót tanítja, nem a felhasználót.
 *
 * ## Ami NEM történik meg
 *
 * A beküldés SOHA nem ad `confirmed` consentet, és NINCS kapcsoló, amivel adhatna. Volt egy
 * `double_opt_in = false` ág, és a review megmérte, mit jelentett: az űrlap-beküldés
 * azonnal jogalapot teremtett, megerősítő link nélkül — vagyis a golden rule 4 egy tenant
 * által átkapcsolható booleanná vált, ADR nélkül.
 *
 * A kontakt `pending`, a consent `pending`, és a marketing addig NEM megy ki — ezt a
 * PreSendGate 3. kapuja érvényesíti, nem ez az action.
 */
final class SubmitForm
{
    /** Egy címre EGY workspace-ből naponta legfeljebb ennyi megerősítő levél megy (minden űrlapján át). */
    public const DOI_PER_WORKSPACE_ADDRESS_PER_DAY = 3;

    /** Egy címre naponta legfeljebb ennyi megerősítő levél megy ÖSSZESEN (minden tenanton át). */
    public const DOI_PER_ADDRESS_PER_DAY = 10;

    public function __construct(
        private readonly ConsentRecorder $consent,
        private readonly ConfirmationTokens $tokens,
        private readonly AddressRiskScorer $risk,
        private readonly JourneyTrigger $journeys,
    ) {}

    public function __invoke(Form $form, FormSubmission $submission): bool
    {
        if (! $form->active) {
            return false;
        }

        // 1. HONEYPOT — kitöltött csapda: bot.
        if (trim((string) $submission->honeypot) !== '') {
            $this->rejectedAsBot($form, 'honeypot');

            return true;
        }

        // 2. TIME-TRAP — túl gyors beküldés: bot.
        if ($submission->elapsedSeconds !== null && $submission->elapsedSeconds < $form->min_seconds) {
            $this->rejectedAsBot($form, 'time_trap');

            return true;
        }

        $email = DomainNormalizer::listKey(trim($submission->email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // 3. CÍMELLENŐRZÉS (A5) — a kockázatos cím nem kerül a listára.
        //
        // Nem a kényelemért: egy spam-trap vagy eldobható cím a listán a KÉZBESÍTHETŐSÉGET
        // rontja, és a kárt minden más ügyfél is fizeti a közös IP-n. A küszöb ugyanaz,
        // amit a 6. kapu használ.
        $risk = $this->risk->riskFor($email);

        if ($risk >= (float) config('sending.validation.risk_threshold', 0.9)) {
            // A CÍM nem kerül a naplóba: egy bot-özön különben idegen címekkel tömné meg.
            Log::info('Űrlap-beiratkozás elutasítva címellenőrzés miatt.', [
                'form' => $form->uuid,
                'risk' => $risk,
            ]);

            return true;
        }

        // 4. A SUPPRESSION-LISTA erősebb, mint az űrlap.
        //
        // Aki leiratkozott vagy panaszt tett, azt egy új űrlap-beküldés NEM hozza vissza —
        // különben a leiratkozás megkerülhető lenne azzal, hogy a címet valaki más
        // (vagy egy bot) újra beírja. A sor törlése operátori művelet.
        if ($this->isSuppressed($email)) {
            return true;
        }

        DB::transaction(function () use ($form, $submission, $email): void {
            $contact = Contact::query()->firstOrNew(['email' => $email]);

            // v1.1 · a FORRÁS csak új kontaktnál íródik: egy importált cím későbbi űrlap-
            // beküldése nem írja át, honnan érkezett eredetileg.
            if (! $contact->exists) {
                $contact->source = 'form';
            }

            // LÉTEZŐ KONTAKTNÁL CSAK AZ ÜRES MEZŐK töltődnek (review S4). A nyilvános űrlapot BÁRKI
            // beküldheti bármely címmel: ha felülírhatná a nevet és az attribútumokat, egy idegen
            // átírhatná egy megerősített feliratkozó adatait (hamis perszonalizáció, szegmens-
            // manipuláció). A saját adat módosítása a preference center tokenes útja.
            foreach (['first_name' => $submission->firstName, 'last_name' => $submission->lastName] as $field => $value) {
                if ($value !== null && $value !== '' && ($contact->{$field} === null || $contact->{$field} === '')) {
                    $contact->{$field} = $value;
                }
            }

            // Az attribútumok közül csak a HIÁNYZÓ kulcsok kerülnek be: a meglévő érték marad.
            $existing = (array) $contact->attributes;
            $contact->attributes = $existing + array_filter(
                $submission->attributes,
                static fn ($value): bool => $value !== null && $value !== '',
            );

            // A `pending` CSAK ÚJ kontaktnál (review N-c): a létező kontakt állapotát — legyen az
            // `subscribed`, `unsubscribed` vagy `bounced` — egy idegen beküldése nem írja át. A
            // feliratkozás megerősítése a levélben lévő linkből történik, nem az űrlapból.
            if (! $contact->exists) {
                $contact->status = 'pending';
            }
            $contact->save();

            // A LISTATAGSÁG csak ha még nincs: a meglévő (akár megerősített) tagságot egy idegen
            // beküldése nem írja vissza `pending`-re (ezzel a marketing a címzettnek leállna).
            if ($form->contact_list_id !== null && ! $contact->lists()->whereKey($form->contact_list_id)->exists()) {
                $contact->lists()->attach($form->contact_list_id, ['status' => 'pending', 'subscribed_at' => null]);
            }

            $consent = $this->consent->request(
                email: $email,
                channel: 'marketing',
                source: 'form',
                ip: $submission->ip,
                userAgent: $submission->userAgent,
                policyVersion: $form->policy_version,
                // A BIZONYÍTÉKBA az kerül, amit a FELHASZNÁLÓ LÁTOTT — nem egy belső kód.
                // Egy hatósági kérdésre a „consent=true" semmit nem ér; a szöveg mindent.
                formText: $form->consent_text,
            );

            // NINCS egyszeres opt-in ág. A megerősítés KIZÁRÓLAG a levélben lévő linkből
            // történhet (golden rule 4) — a `ConsentRecorder::confirm()` ezen kívül
            // egyszeri használatra kötött és visszavont consentet nem támaszt fel.
            $this->sendConfirmation($form, $contact, $email);
        });

        /*
         | JOURNEY-TRIGGER (B3 · 69. kritérium) — a TRANZAKCIÓ UTÁN.
         |
         | Nem a tranzakción belül: a beléptetés levelet küldhet (a journey első lépése),
         | és egy még nem commitolt kontaktra hivatkozó levél a worker oldalán „nem
         | találom" hibába futna. Ugyanaz az `afterCommit`-gondolat, mint az indexelő jobnál.
         |
         | A `form_signup` trigger a MI rendszerünkből jön, tehát a forrás bizonyított — a
         | 71. kritérium hamisítható-trigger szabálya (megerősített consent) ide nem
         | vonatkozik. A journey első levele viszont a PreSendGate 3. kapuján megy át, ami a
         | `pending` consenttel elutasít: a beléptetés megtörténik, a marketing levél nem
         | megy ki, amíg a címzett nem erősít meg.
         */
        $this->journeys->fire(TriggerType::FormSignup, $email, [
            'form' => (string) $form->uuid,
            'list' => $form->contact_list_id === null ? null : (string) $form->list?->uuid,
        ]);

        return true;
    }

    /**
     * A MEGERŐSÍTŐ LEVÉL — TRANZAKCIÓS csatornán.
     *
     * Nem lehet marketing: a 3. kapu épp azt kérdezné, van-e consent — és ha lenne, nem
     * kellene megerősítő levél. Ez a levél a consent MEGSZERZÉSÉRŐL szól, tehát a
     * szolgáltatás működéséhez tartozik.
     */
    private function sendConfirmation(Form $form, Contact $contact, string $email): void
    {
        $list = $form->list;
        $token = $this->tokens->issue(
            (int) $contact->workspace_id,
            $email,
            'marketing',
            $list?->uuid,
        );

        $url = rtrim((string) config('app.url'), '/').'/c/'.$token;

        $from = $list?->from_email ?? config('mail.from.address');

        if (! is_string($from) || $from === '') {
            // Feladó nélkül nem tudunk küldeni. HANGOSAN naplózunk: a beiratkozás
            // ilyenkor „elveszik", és ezt a tenantnak látnia kell a naplóban.
            Log::warning('Nincs feladó a megerősítő levélhez.', ['form' => $form->uuid]);

            return;
        }

        // CÍMENKÉNTI PLAFON a megerősítő LEVÉLRE (review S1 → SN1 → r3 S1/N2).
        //
        // CSAK A LEVÉL marad ki, a beiratkozás nem: a kontakt és a `pending` consent fent már
        // létrejött. Korábban a plafon az egész beküldést eldobta — egy idegen cím napi három
        // beküldésével bárki blokkolhatta az áldozat valódi feliratkozását MINDEN tenantnál.
        //
        // Két kulcs (a cím kulcsolt hash-ével, a cím maga nem kerül a kulcsba):
        //  - (workspace, cím) — napi 3: egy tenant űrlapjai ennyit küldhetnek egy címre;
        //  - cím — napi 10, minden tenanton át: a sok tenanton át szervezett bombázás plafonja.
        //
        // ATOMIKUS FOGLALÁS: előbb NÖVELÜNK, és a visszaadott értéket nézzük. A korábbi
        // „ellenőrzés, küldés, növelés" sorrendben a párhuzamos beküldések (több IP-ről) mind
        // ugyanazt a régi értéket látták, és együtt túlléphették a plafont. Ha nem ment ki levél
        // (plafon, kapu-elutasítás, idempotens visszajátszás, kivétel), a foglalás visszacsökken.
        //
        // N3 (dokumentálva): a globális kulcs tenantokon átívelő, ezért a plafonon „elnyelt" levél
        // gyenge időzítési / viselkedési jelet ad arról, hogy a címet máshol is beíratták. A
        // kulcs HMAC (nincs benne cím), a válasz mindig ugyanaz — a maradék kockázatot vállaljuk.
        $hash = KeyedHash::of('email', $email);
        $workspaceKey = 'doi-mail:ws:'.(int) $contact->workspace_id.':'.$hash;
        $globalKey = 'doi-mail:'.$hash;

        $workspaceHits = RateLimiter::increment($workspaceKey, 86400);
        $globalHits = RateLimiter::increment($globalKey, 86400);
        // A FELSZABADÍTÁS csak élő (pozitív) számlálón csökkent (review N-b): a közben lejárt kulcs
        // a `decrement`-tel -1-ről indulna újra, és a következő napi keretet csendben megnövelné.
        $release = static function () use ($workspaceKey, $globalKey): void {
            foreach ([$workspaceKey, $globalKey] as $key) {
                if (RateLimiter::attempts($key) > 0) {
                    RateLimiter::decrement($key, 86400);
                }
            }
        };

        if ($workspaceHits > self::DOI_PER_WORKSPACE_ADDRESS_PER_DAY || $globalHits > self::DOI_PER_ADDRESS_PER_DAY) {
            $release();
            Log::info('Megerősítő levél címenkénti plafonon — a beiratkozás pending, levél nélkül.', ['form' => $form->uuid]);

            return;
        }

        try {
            $result = app(SendMessage::class)([
                'channel' => 'transactional',
                'from_email' => $from,
                'from_name' => $list?->from_name,
                'to' => [$email],
                'subject' => (string) config('consent.confirmation.subject'),
                'text' => "Kérjük, erősítsd meg a feliratkozásod ezen a linken:\n\n".$url
                    ."\n\nHa nem te kérted, egyszerűen hagyd figyelmen kívül ezt a levelet — "
                    .'megerősítés nélkül nem küldünk semmit.',
                'html' => '<p>Kérjük, erősítsd meg a feliratkozásod:</p>'
                    .'<p><a href="'.e($url).'">Feliratkozás megerősítése</a></p>'
                    .'<p>Ha nem te kérted, egyszerűen hagyd figyelmen kívül ezt a levelet — '
                    .'megerősítés nélkül nem küldünk semmit.</p>',
                // A megerősítő levél EGYSZER menjen ki beküldésenként: a kulcs a címből és az
                // űrlapból származik, tehát a dupla beküldés nem küld két levelet.
                'idempotency_key' => 'optin:'.hash('sha256', $form->uuid.'|'.$email.'|'.now()->format('YmdH')),
            ]);
        } catch (\Throwable $e) {
            // Kivételnél sem ment ki levél: a foglalás felszabadul.
            $release();

            throw $e;
        }

        // A KAPU ÁLTAL ELUTASÍTOTT levél (pl. hitelesítetlen feladó-domain) és az idempotens
        // VISSZAJÁTSZÁS (review N2: új levél nem keletkezett) nem fogyasztja a keretet.
        if (! $result->decision->allowed || $result->replayed) {
            $release();
        }
    }

    private function isSuppressed(string $email): bool
    {
        return Suppression::query()
            ->where('email', $email)
            ->get()
            ->contains(fn (Suppression $suppression) => $suppression->isActive());
    }

    private function rejectedAsBot(Form $form, string $reason): void
    {
        // NEM PII: az űrlap azonosítója és a szűrés oka. A cím szándékosan nem kerül bele —
        // egy bot-özön különben a naplót tömné meg idegen címekkel.
        Log::info('Űrlap-beküldés bot-szűrőn elakadt.', ['form' => $form->uuid, 'reason' => $reason]);
    }
}
