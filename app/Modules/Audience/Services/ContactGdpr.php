<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services;

use App\Modules\Audience\Jobs\SyncContactToIndex;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\ConsentEvent;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Suppression\Enums\SuppressionReason;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * GDPR — EXPORT és TÖRLÉS a kontaktra (52. kritérium).
 *
 * ## Az export
 *
 * A 15. cikk szerinti hozzáférési jog nem „az adatbázis-sorod", hanem MINDEN, amit róla
 * tárolunk: a kontakt maga, a listatagságok, a címkék, a consent AKTUÁLIS állapota, a
 * consent TÖRTÉNETE, és az aktivitás (mit kapott, mit nyitott meg). Az utolsó a
 * legkönnyebben elfelejtett, mert az másik modulban (Events) él.
 *
 * ## A törlés — és ami MEGMARAD
 *
 * A 17. cikk szerinti törlés itt ANONIMIZÁLÁS, nem `DELETE`, és ennek két oka van:
 *
 *  1. **A SUPPRESSION-SOR MEGMARAD.** Ez nem a jog kijátszása, hanem a jog teljesítése: aki
 *     leiratkozott vagy panaszt tett, annak a tiltását nem szabad elfelejteni. Ha a sort
 *     törölnénk, egy későbbi import ugyanazt a címet újra felvenné — vagyis a törlési kérés
 *     eredménye az lenne, hogy megint kap levelet. A soron a cím helyén a peppered HMAC áll,
 *     nyers cím nélkül: a tiltás így ellenőrizhető marad anélkül, hogy a címet tárolnánk.
 *  2. **A CONSENT-BIZONYÍTÉK megmarad**, hash-elve. A `consent_events` append-only és
 *     trigger védi; a nyers címet kivesszük, a `email_hash` marad. Egy hatósági kérdésre
 *     („volt-e jogalap, amikor küldtek?") így is válaszolni tudunk, a törölt érintett
 *     azonosítása nélkül.
 *
 * A `messages` és a `message_events` redakciója a `logs:redact-subject` gazdája (A3) — ez a
 * szolgáltatás azt HÍVJA, nem duplikálja.
 */
final class ContactGdpr
{
    /**
     * A 15. cikk szerinti export.
     *
     * @return array<string, mixed>
     */
    public function export(Contact $contact): array
    {
        $email = (string) $contact->email;

        return [
            'contact' => [
                'id' => (string) $contact->uuid,
                'email' => $email,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'attributes' => (array) $contact->attributes,
                'status' => (string) $contact->status,
                'external_id' => $contact->external_id,
                // A CÍMELLENŐRZÉS eredménye is róla szóló adat: az A5 saját docblockja
                // mondja ki, hogy ez a rendszer egyik legérzékenyebb adata.
                'validation_verdict' => $contact->validation_verdict,
                'validation_risk' => $contact->validation_risk,
                'subscribed_at' => $contact->subscribed_at?->toIso8601String(),
                'unsubscribed_at' => $contact->unsubscribed_at?->toIso8601String(),
                'created_at' => $contact->created_at?->toIso8601String(),
            ],
            'lists' => $contact->lists->map(fn ($list) => [
                'name' => (string) $list->name,
                'status' => (string) $list->pivot->status,
                'subscribed_at' => $list->pivot->subscribed_at,
                'unsubscribed_at' => $list->pivot->unsubscribed_at,
            ])->all(),
            'tags' => $contact->tags->pluck('name')->all(),
            // A CONSENT AKTUÁLIS állapota…
            'consents' => Consent::query()->where('email', $email)->get()->map(fn (Consent $consent) => [
                'channel' => (string) $consent->channel,
                'status' => (string) $consent->status,
                'legal_basis' => (string) $consent->legal_basis,
                'source' => $consent->source,
                'policy_version' => $consent->policy_version,
                'confirmed_at' => $consent->confirmed_at?->toIso8601String(),
                'revoked_at' => $consent->revoked_at?->toIso8601String(),
            ])->all(),
            // …és a TÖRTÉNETE. A kettő nem ugyanaz, és az érintettnek mindkettőhöz joga van:
            // az állapotból nem derül ki, mikor és milyen szöveggel adta a hozzájárulást.
            'consent_history' => ConsentEvent::query()
                ->where('email_hash', KeyedHash::of('email', $email))
                ->orderBy('occurred_at')
                ->get()
                ->map(fn (ConsentEvent $event) => [
                    'type' => (string) $event->type,
                    'channel' => (string) $event->channel,
                    'source' => $event->source,
                    'policy_version' => $event->policy_version,
                    // AMIT LÁTOTT — ez a bizonyíték érdemi része.
                    'form_text' => $event->form_text,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ])->all(),
            // AZ AKTIVITÁS: mit kapott, mit nyitott meg. A legkönnyebben elfelejtett rész,
            // mert másik modulban (Events) él — az érintett szempontjából viszont ez a
            // legérdekesebb adat rólunk.
            'activity' => $this->activity($email),
            // A CSONKOLÁS TÉNYE is része az exportnak: egy jelzés nélkül levágott lista azt
            // állítaná, hogy ennyi az összes — pedig nem.
            'activity_truncated' => $this->activityCount($email) > self::ACTIVITY_LIMIT,
            'activity_total' => $this->activityCount($email),
            // A NORMALIZÁLT ok (a legacy `hard_bounce`/`soft_bounce` → `bounce`) és a jelleg (`detail`) — az
            // érintett ugyanazt lássa, mint a felület és a `/v1` (m7 polish, D274).
            'suppressions' => Suppression::query()->where('email', $email)->get()->map(fn (Suppression $row) => [
                'reason' => SuppressionReason::fromStored((string) $row->reason)?->value ?? (string) $row->reason,
                'detail' => $row->detail ?? SuppressionReason::legacyDetail((string) $row->reason),
                'applies_to' => (string) $row->applies_to,
                'created_at' => $row->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /** Az exportált aktivitás felső darabszáma. A csonkolás TÉNYE is az exportba kerül. */
    private const ACTIVITY_LIMIT = 1000;

    /**
     * AZ AKTIVITÁS — KIS/NAGYBETŰ-FÜGGETLEN egyezéssel.
     *
     * A `contacts.email` a `listKey()` alakja (a helyi rész is kisbetűs), a
     * `message_events.recipient` viszont a `DomainNormalizer::email()` alakja — ott a helyi
     * rész SZÁNDÉKOSAN érintetlen (RFC 5321 §2.4). Szigorú egyezéssel a `Nagy.Anna@…` címre
     * szóló export NULLA aktivitást adott vissza, miközben az adatbázisban ott voltak a
     * sorai — mérve. Egy 15. cikk szerinti kérésre ez hamis válasz.
     *
     * @return list<array<string, mixed>>
     */
    private function activity(string $email): array
    {
        return MessageEvent::query()
            ->whereRaw('lower(recipient) = ?', [$email])
            ->orderByDesc('occurred_at')
            ->limit(self::ACTIVITY_LIMIT)
            ->get()
            ->map(fn (MessageEvent $event) => [
                'type' => (string) $event->type,
                'message_id' => $event->message_uuid,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->all();
    }

    private function activityCount(string $email): int
    {
        return MessageEvent::query()->whereRaw('lower(recipient) = ?', [$email])->count();
    }

    /**
     * A 17. cikk szerinti törlés — ANONIMIZÁLÁS, a tiltás megőrzésével.
     *
     * @return array<string, int>
     */
    public function erase(Contact $contact): array
    {
        $email = (string) $contact->email;
        $hash = KeyedHash::of('email', $email);

        // TENANT-HATÁR: a hívó a modellt adja, és a lenti írások a modellt MEGKERÜLIK.
        // Explicit ellenőrzés, mert az elsődleges kulcsra menő `update()` pontosan annyira
        // biztonságos, amennyire a hívó figyelmes — a `BelongsToWorkspace` őre itt nem fut.
        $context = app(WorkspaceContext::class);

        if (! $context->hasWorkspace() || (int) $contact->workspace_id !== $context->workspaceId()) {
            throw new RuntimeException(sprintf(
                'A törlés csak a saját workspace kontaktjára futtatható (%s ≠ %s).',
                (string) $contact->workspace_id,
                (string) ($context->hasWorkspace() ? $context->workspaceId() : 'nincs kontextus'),
            ));
        }

        /*
         | A NAPLÓ-OLDALI TÖRLÉS az A3 gazdájáé — és VALÓBAN meghívjuk.
         |
         | A korábbi docblock azt állította, hogy „a `logs:redact-subject` (A3) redaktálja, ez
         | a szolgáltatás azt HÍVJA" — nem hívta. A review megmérte a következményt: az
         | `erase()` után a `messages` táblában 1, a `message_events.recipient`-ben 3 nyers
         | cím maradt, és `erased_recipients` sor NEM született.
         |
         | Az utolsó a legsúlyosabb: az `ErasedRecipient` tábla azért létezik, hogy a MÁR
         | KIKÜLDÖTT levélben lévő nyomkövető pixel egyetlen betöltéssel ne írja vissza a
         | címet — sor nélkül a törlés önmagát vonta volna vissza a következő megnyitásnál.
         |
         | Egy implementáció, egy hívási pont: a parancs a CLI belépési pontja, a törlési
         | logika pedig ugyanaz mindkét úton.
         */
        // A `--workspace` a workspace UUID-JÁT (vagy slugját) várja, nem a belső id-t: az
        // id-vel a parancs `invalid input syntax for type uuid`-del elhasalt volna, és a
        // napló-oldali törlés csendben nem futott volna le.
        //
        // A KONTEXTUST VISSZA KELL ÁLLÍTANI a hívás után. A parancs a `finally` ágában
        // `forget()`-el — CLI-ként ez helyes —, egy szolgáltatásból hívva viszont ezzel
        // elveszik a hívó tenant-kontextusa, és az utána következő lekérdezések a
        // `WorkspaceScope` fail-closed ágára futnak (`1 = 0`). Mérve: az `erase()`
        // `suppressions_kept` értéke 0 lett, miközben a tiltás megmaradt — vagyis a művelet
        // HAMISAN jelentette volna, hogy nincs mit megőrizni.
        Artisan::call('logs:redact-subject', [
            'email' => $email,
            '--workspace' => (string) $contact->workspace->uuid,
        ]);

        $context->set($contact->workspace);

        return DB::transaction(function () use ($contact, $email, $hash): array {
            // 1. A SUPPRESSION-SOR MEGMARADT, a cím nélkül — de ezt már a fenti,
            //    PLATFORM-SZINTŰ redakció végezte el (`redactSuppressions`), nem mi.
            //
            //    A duplikálás itt nem ártalmatlan volt: a saját ciklusom a MÁR redaktált
            //    sort nem találta meg (`where('email', $email)` → 0 találat), tehát a
            //    visszajelentett darabszám nulla lett volna, miközben a tiltás megmaradt —
            //    vagyis a művelet HAMISAN jelentette volna, hogy nincs mit megőrizni.
            //
            //    A darabszámot ezért a HASH alapján számoljuk: az a mező marad meg, és
            //    pontosan az teszi a tiltást felismerhetővé nyers cím nélkül.
            $suppressions = Suppression::query()->where('email_hash', $hash)->count();

            // 2. A CONSENT-BIZONYÍTÉK megmarad, hash-elve — és nyers címet SOHA nem is
            //    tartalmazott.
            //
            //    A `consent_events.email` korábban a nyers címet tárolta, a tábla viszont
            //    append-only és TRIGGER védi: a cím így VÉGLEGESEN eltávolíthatatlan lett
            //    volna (mérve: `SQLSTATE[P0001] … megváltoztathatatlan (UPDATE)`), vagyis a
            //    17. cikk szerinti törlés teljesíthetetlen. A `ConsentRecorder` ezért `null`-t
            //    ír a `email` oszlopba; a `email_hash` a bizonyításhoz elég (ugyanaz a
            //    cím-e), az azonosításhoz nem — és pontosan ez a cél.
            $consents = Consent::query()->where('email', $email)->count();

            foreach (Consent::query()->where('email', $email)->get() as $consent) {
                DB::table('consents')->where('id', $consent->getKey())->update([
                    'email' => '',
                    'email_hash' => $hash,
                ]);
            }

            // 3. A KONTAKT anonimizálva. A sor MEGMARAD, mert a listatagságok és az
            //    aktivitás statisztikái (B4) különben visszamenőleg megváltoznának — a
            //    kampány „elküldött 1000" száma nem lehet 999, mert valaki törlést kért.
            DB::table('contacts')->where('id', $contact->getKey())->update([
                'email' => 'torolt-'.$contact->uuid.'@'.config('audience.erased_domain', 'erased.invalid'),
                'email_hash' => $hash,
                'first_name' => null,
                'last_name' => null,
                'attributes' => '{}',
                'external_id' => null,
                'status' => 'unsubscribed',
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. A KÜLSŐ KERESŐINDEX — és ezt KÉZZEL kell elindítani.
            //
            // A fenti írások szándékosan MEGKERÜLIK a modellt (a mutátor újraszámolná a
            // hasht az üres címből, és a suppression-tiltás felismerhetetlenné válna) — ezzel
            // viszont a modell `deleted` hookja sem fut le, tehát az indexelő job sem indul
            // el. A cím így bent maradt volna a Meilisearchben: a törlési kérés után is
            // kereshetően, egy KÜLSŐ rendszerben.
            //
            // Aki a modellt megkerüli, annak a modell MELLÉKHATÁSAIT is át kell vennie.
            SyncContactToIndex::dispatch(
                (string) $contact->uuid,
                (int) $contact->workspace_id,
                true,
            );

            return [
                'suppressions_kept' => $suppressions,
                'consents_anonymised' => $consents,
                'contacts_anonymised' => 1,
            ];
        });
    }

    /**
     * A cím szerinti keresés — az érintetti kérés a CÍMMEL érkezik, nem uuid-val.
     *
     * AZ ÉLŐ SOR AZ ELSŐDLEGES. Az anonimizált „sírkövön" az `email_hash` MEGMARAD (hogy a
     * korábbi törlés ténye ellenőrizhető legyen), tehát egy újra-feliratkozás után két sor
     * felel meg a hash-nek. Rendezés nélkül a MÁSODIK érintetti kérés (export vagy törlés) a
     * sírkövön dolgozott: sikert jelentett, és az ÚJ kontakt PII-je bent maradt — mérve.
     */
    public function findByEmail(string $email): ?Contact
    {
        $hash = KeyedHash::of('email', DomainNormalizer::listKey(trim($email)));

        return Contact::query()->where('email_hash', $hash)->first()
            ?? Contact::query()->withTrashed()->where('email_hash', $hash)->orderByDesc('id')->first();
    }
}
