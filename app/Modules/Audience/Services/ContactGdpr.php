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
use App\Modules\Suppression\Models\Suppression;
use Illuminate\Support\Facades\DB;

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
            'activity' => MessageEvent::query()
                ->where('recipient', $email)
                ->orderByDesc('occurred_at')
                ->limit(1000)
                ->get()
                ->map(fn (MessageEvent $event) => [
                    'type' => (string) $event->type,
                    'message_id' => $event->message_uuid,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ])->all(),
            'suppressions' => Suppression::query()->where('email', $email)->get()->map(fn (Suppression $row) => [
                'reason' => (string) $row->reason,
                'applies_to' => (string) $row->applies_to,
                'created_at' => $row->created_at?->toIso8601String(),
            ])->all(),
        ];
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

        return DB::transaction(function () use ($contact, $email, $hash): array {
            // 1. A SUPPRESSION-SOR MEGMARAD, a cím nélkül. A `email_hash` alapján a kapu
            //    továbbra is felismeri a tiltást — nyers cím tárolása nélkül.
            $suppressions = 0;

            foreach (Suppression::query()->where('email', $email)->get() as $row) {
                // A mutátort MEGKERÜLJÜK: az újraszámolná a hasht az üres címből, és a
                // tiltás felismerhetetlenné válna. A hash marad, a cím ürül.
                DB::table('suppressions')->where('id', $row->getKey())->update([
                    'email' => '',
                    'email_hash' => $hash,
                    'detail' => null,
                ]);

                $suppressions++;
            }

            // 2. A CONSENT-BIZONYÍTÉK megmarad, hash-elve. A tábla append-only és TRIGGER
            //    védi — a nyers cím kivétele ezért `DB::table()`-lel sem megy. Ez tudatos:
            //    a bizonyíték-tábla nyers címét a `logs:redact-subject` (A3) redaktálja,
            //    egyetlen, auditált úton.
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

    /** A cím szerinti keresés — az érintetti kérés a CÍMMEL érkezik, nem uuid-val. */
    public function findByEmail(string $email): ?Contact
    {
        return Contact::query()
            ->withTrashed()
            ->where('email_hash', KeyedHash::of('email', DomainNormalizer::listKey(trim($email))))
            ->first();
    }
}
