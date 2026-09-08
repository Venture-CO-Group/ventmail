<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services;

use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\ConsentEvent;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Suppression\Models\Suppression;
use Illuminate\Support\Facades\DB;

/**
 * A CONSENT EGYETLEN írási pontja (50., 53., 57. kritérium).
 *
 * Két dolgot ír, MINDIG együtt, MINDIG tranzakcióban:
 *
 *  - a `consents` sort — a materializált AKTUÁLIS állapotot, amit a PreSendGate 3. kapuja
 *    egyetlen lekérdezéssel megkérdez minden küldésnél;
 *  - a `consent_events` sort — a BIZONYÍTÉKOT, append-only.
 *
 * Miért nem elég az első: az állapot felülíródik. Egy visszavonás után a sorból nem derül ki,
 * hogy VOLT-E érvényes hozzájárulás akkor, amikor küldtünk — pedig egy hatósági kérdésre
 * pontosan ezt kell megmutatni. Miért nem elég a második: a kapu nem tud minden küldésnél
 * eseménytörténetet visszajátszani ezer címzettre.
 *
 * NYERS IP SOHA (53. kritérium). Az IP személyes adat, a bizonyításhoz viszont elég, hogy
 * UGYANARRÓL a címről jött-e a kérés és a megerősítés — ehhez a peppered HMAC elegendő. A
 * peppertelen hash nem lenne elég: az IPv4-tér 4 milliárd elemű, egy sózatlan hash-t
 * másodpercek alatt visszafejt egy szótár.
 */
final class ConsentRecorder
{
    /**
     * BEIRATKOZÁSI KÉRÉS (double opt-in első lépése).
     *
     * A consent `pending`-ben marad: ettől a pillanattól NEM megy ki marketing. Aki a
     * kérésre azonnal `confirmed`-et ír, az single opt-int csinál double opt-in néven — és a
     * golden rule 4 pontosan ezt tiltja.
     */
    public function request(
        string $email,
        string $channel = 'marketing',
        ?string $source = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $policyVersion = null,
        ?string $formText = null,
    ): Consent {
        $email = DomainNormalizer::listKey(trim($email));

        return DB::transaction(function () use ($email, $channel, $source, $ip, $userAgent, $policyVersion, $formText): Consent {
            $consent = Consent::query()
                ->where('email', $email)
                ->where('channel', $channel)
                ->first();

            // A MÁR MEGERŐSÍTETT consentet egy újabb kérés NEM rontja vissza `pending`-re:
            // aki kétszer iratkozik fel, ne veszítse el a jogalapot (és ne kapjon fölös
            // megerősítő levelet).
            //
            // BIZONYÍTÉK-SZENNYEZÉS ELLEN: ha már van MEGERŐSÍTETT consent, a publikus
            // (hitelesítetlen) beküldésből NEM írunk új `requested` eseményt. Bárki
            // beküldheti egy áldozat címét a nyilvános űrlapon, és a TÁMADÓ `ip_hash`-e,
            // user-agentje és az általa választott `policy_version` bekerült volna az
            // áldozat bizonyíték-történetébe — egy olyan táblába, amit a trigger miatt
            // utólag sem javítani, sem törölni nem lehet. Egy jogi vitában ez pont a
            // bizonyíték értékét rontja.
            if ($consent !== null && $consent->isConfirmed()) {
                return $consent;
            }

            if ($consent === null) {
                $consent = Consent::query()->create([
                    'email' => $email,
                    'channel' => $channel,
                    'status' => 'pending',
                    'source' => $source,
                    'policy_version' => $policyVersion,
                ]);

                $consent->forceFill(['ip_hash' => $this->hashIp($ip)])->save();
            }

            $this->event($consent, 'requested', $ip, $userAgent, $policyVersion, $formText, $source);

            return $consent->fresh();
        });
    }

    /**
     * MEGERŐSÍTÉS (double opt-in második lépése) — innentől mehet a marketing.
     *
     * A `confirmed_at` bizonyíték: mikortól volt jogalapunk. A visszaesés (`revoked_at`) ezt
     * NEM törli — a két időpont együtt adja meg az ablakot, amiben küldeni lehetett.
     */
    public function confirm(
        Consent $consent,
        ?string $ip = null,
        ?string $userAgent = null,
    ): Consent {
        return DB::transaction(function () use ($consent, $ip, $userAgent): Consent {
            /*
             | A VISSZAVONT consentet egy megerősítő link NEM TÁMASZTJA FEL.
             |
             | Mérve, mit jelentett a hiánya: megerősített consent → one-click leiratkozás
             | (suppression + revoke) → az EREDETI `/c/{token}` GET (a TTL egy hét!) →
             | `status=confirmed`, `revoked_at=NULL`, a kontakt újra `subscribed`, és a 3.
             | kapu engedélyezőt ad. Vagyis a SafeLinks-előtöltés vagy egy téves kattintás a
             | régi opt-in levélben VISSZAÍRJA a jogalapot arra, aki visszavonta.
             |
             | Ma a 2. kapu (suppression) még megfogná a küldést — de a suppression-sor
             | levétele dokumentáltan operátori művelet, és utána a marketing megy. A
             | `consents` sor és a bizonyíték-történet innentől olyan jogalapot állítana,
             | amit az érintett megszüntetett.
             |
             | Új hozzájárulás új KÉRÉST igényel (`request()`), nem egy régi link
             | újrajátszását.
             */
            if ($consent->status === 'revoked' || $consent->revoked_at !== null) {
                return $consent;
            }

            // A SUPPRESSION-LISTA erősebb: aki leiratkozott vagy panaszt tett, annak a
            // tiltását egy megerősítő link nem írhatja felül.
            if ($this->isSuppressed((string) $consent->email)) {
                return $consent;
            }

            // EGYSZERI HASZNÁLAT: a megerősítés a `requested` eseményhez kötött, és
            // ugyanahhoz a kéréshez MÁSODSZOR nem születik `confirmed`. Enélkül a token a
            // teljes TTL-je alatt korlátlanul újrajátszható volt.
            $request = ConsentEvent::query()
                ->where('consent_id', $consent->getKey())
                ->where('type', 'requested')
                ->orderByDesc('id')
                ->first();

            if ($request !== null) {
                $alreadyUsed = ConsentEvent::query()
                    ->where('consent_id', $consent->getKey())
                    ->where('type', 'confirmed')
                    ->where('payload->request', (string) $request->uuid)
                    ->exists();

                if ($alreadyUsed) {
                    return $consent;
                }
            }

            $consent->forceFill([
                'status' => 'confirmed',
                'confirmed_at' => $consent->confirmed_at ?? now(),
            ])->save();

            $this->event(
                $consent,
                'confirmed',
                $ip,
                $userAgent,
                $consent->policy_version,
                null,
                $consent->source,
                ['request' => $request === null ? null : (string) $request->uuid],
            );

            return $consent->fresh();
        });
    }

    /**
     * VISSZAVONÁS — a leiratkozás és a preference center hívja.
     *
     * A sort NEM töröljük: a visszavonás TÉNYE is bizonyíték, és a suppression-listával
     * együtt ez akadályozza meg, hogy egy későbbi import újra „megerősítettnek" könyvelje.
     */
    public function revoke(
        Consent $consent,
        ?string $source = null,
        ?string $ip = null,
    ): Consent {
        return DB::transaction(function () use ($consent, $source, $ip): Consent {
            $consent->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

            $this->event($consent, 'revoked', $ip, null, $consent->policy_version, null, $source);

            return $consent->fresh();
        });
    }

    /**
     * IMPORTÁLT lista — jogalappal, de a MI oldalunkon nem megerősítve.
     *
     * Ez a metódus SZÁNDÉKOSAN nem ad `confirmed` állapotot. Aki importál, az azt állítja,
     * hogy máshol volt hozzájárulás — a bizonyíték viszont nincs a kezünkben, és a
     * kézbesíthetőségi kárt (panasz, blocklist) MI fizetjük meg. Az import `pending`, és a
     * tenantnak meg kell erősíttetnie a címeket, vagy vállalnia, hogy a kapu elutasít.
     */
    public function imported(string $email, string $channel, string $evidence): Consent
    {
        $consent = $this->request($email, $channel, 'import');

        $this->event(
            $consent,
            'imported',
            null,
            null,
            $consent->policy_version,
            $evidence,
            'import',
        );

        return $consent;
    }

    /** @param array<string, mixed> $payload */
    /**
     * LISTÁNKÉNTI preferencia-változás — bizonyítékkal (55., 57. kritérium).
     *
     * A listánkénti be- és kikapcsolás is a consent HATÓKÖRÉT módosítja, tehát nyomot kell
     * hagynia: egy „miért kaptam ezt a levelet?" kérdésre a tagsági állapotból nem derül ki,
     * hogy a címzett MIKOR és HONNAN kapcsolta be. A `consents` sor viszont NEM változik —
     * a jogalap a csatornára szól, a lista annak a hatóköre.
     */
    public function listPreference(string $email, string $listUuid, bool $subscribed): void
    {
        $email = DomainNormalizer::listKey(trim($email));

        $consent = Consent::query()->where('email', $email)->where('channel', 'marketing')->first();

        if ($consent === null) {
            return;
        }

        $this->event(
            $consent,
            $subscribed ? 'list_subscribed' : 'list_unsubscribed',
            null,
            null,
            $consent->policy_version,
            null,
            'preference_center',
            ['list' => $listUuid],
        );
    }

    private function event(
        Consent $consent,
        string $type,
        ?string $ip,
        ?string $userAgent,
        ?string $policyVersion,
        ?string $formText,
        ?string $source,
        array $payload = [],
    ): void {
        ConsentEvent::query()->create([
            'consent_id' => $consent->getKey(),
            // A NYERS címet átadjuk, de a MODELL csak a hash-ét tárolja (nincs is oszlop a
            // nyers alaknak). Az invariáns így nem a hívók figyelmén múlik.
            'email' => $consent->email,
            'type' => $type,
            'channel' => $consent->channel,
            'legal_basis' => $consent->legal_basis ?? 'consent',
            'source' => $source,
            'ip_hash' => $this->hashIp($ip),
            'user_agent_hash' => $userAgent === null ? null : KeyedHash::of('user_agent', $userAgent),
            'policy_version' => $policyVersion,
            'form_text' => $formText,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }

    /** A cím TILTOTT-e? A suppression-lista minden consent-műveletnél erősebb. */
    private function isSuppressed(string $email): bool
    {
        return Suppression::query()
            ->where('email', $email)
            ->get()
            ->contains(fn (Suppression $suppression) => $suppression->isActive());
    }

    /** NYERS IP SOHA — peppered HMAC, a `KeyedHash` `ip` domainjével (53. kritérium). */
    private function hashIp(?string $ip): ?string
    {
        return $ip === null || trim($ip) === '' ? null : KeyedHash::of('ip', trim($ip));
    }
}
