<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services;

use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\ConsentEvent;
use App\Modules\Sending\Services\DomainNormalizer;
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
            // megerősítő levelet). A bizonyíték-esemény viszont MINDIG megszületik.
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
            $consent->forceFill([
                'status' => 'confirmed',
                'confirmed_at' => $consent->confirmed_at ?? now(),
                'revoked_at' => null,
            ])->save();

            $this->event($consent, 'confirmed', $ip, $userAgent, $consent->policy_version, null, $consent->source);

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

    private function event(
        Consent $consent,
        string $type,
        ?string $ip,
        ?string $userAgent,
        ?string $policyVersion,
        ?string $formText,
        ?string $source,
    ): void {
        ConsentEvent::query()->create([
            'consent_id' => $consent->getKey(),
            'email' => $consent->email,
            'type' => $type,
            'channel' => $consent->channel,
            'legal_basis' => $consent->legal_basis ?? 'consent',
            'source' => $source,
            'ip_hash' => $this->hashIp($ip),
            'user_agent_hash' => $userAgent === null ? null : KeyedHash::of('user_agent', $userAgent),
            'policy_version' => $policyVersion,
            'form_text' => $formText,
            'occurred_at' => now(),
        ]);
    }

    /** NYERS IP SOHA — peppered HMAC, a `KeyedHash` `ip` domainjével (53. kritérium). */
    private function hashIp(?string $ip): ?string
    {
        return $ip === null || trim($ip) === '' ? null : KeyedHash::of('ip', trim($ip));
    }
}
