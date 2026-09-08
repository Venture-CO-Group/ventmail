<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * A LEIRATKOZÓ link tokenje (RFC 8058 one-click).
 *
 * Titkosítva hordozza a workspace-t, az üzenetet és a címzettet. Ugyanaz a döntés, mint a
 * nyomkövetésnél, ugyanazzal az indokkal: a cím személyes adat, a link viszont levélkliensen,
 * proxyn és — a one-click esetén — a GMAIL SZERVERÉN megy át.
 *
 * ## A CÉL a tokenen BELÜL van (`p`), és ez nem redundancia
 *
 * A `Crypt` az `APP_KEY`-hez köt, tehát MINDEN azzal a kulccsal kiadott token megfejthető
 * MINDEN olyan kóddal, ami ugyanazt a kulcsot használja. A `TrackingTokens` (A3) ugyanazt a
 * `w`/`m`/`r` hármast adja ki ugyanabban a borítékban — cél-elválasztás nélkül tehát a
 * NYOMKÖVETÉSI token érvényes leiratkozó tokenné válik.
 *
 * Miért súlyos ez: a nyomkövetési token szándékosan ALACSONY ÉRTÉKŰ, SZÉLES FELÜLETEN
 * szétszóródó hitelesítő — benne van a levél minden `href`-jében és a nyitás-pixel `src`-jében,
 * a védelmi átjárók (SafeLinks, vírusszűrő proxy) végiglátogatják ÉS NAPLÓZZÁK, a Gmail
 * képproxyja lekéri, továbbított és megosztott levélben is utazik. A `/u/` token ezzel szemben
 * ÍRÁSI KÉPESSÉG egy olyan listára, amiről a tenant API-ból nem is vehet le senkit
 * (`SuppressionReason::Unsubscribe::isIrrevocableByTenant()`). Vagyis a levél minden linkje
 * visszavonhatatlan leiratkozás lett volna — mérve: egy klikk-tokennel a `POST /u/{token}`
 * 200-at adott és felvette a címzettet.
 *
 * A `p` mező a TITKOSÍTOTT payloadon belül van, tehát a MAC védi: nem átírható. A
 * `parse()` csak a saját célt fogadja el, és ugyanez a zár a `TrackingTokens`-ben is ott van
 * — a védelem MINDKÉT irányban méretett.
 *
 * ## A kanonikus base64 — és amit NEM ad
 *
 * A `parse()` a KANONIKUS base64-alakot követeli meg (dekódol → visszakódol → bájtra
 * egyeztet), ami elutasítja a belső `=`-t, a levágott paddinget és a fel nem használt záró
 * bitek nem nulla variánsait.
 *
 * Amit ez NEM zár: a Laravel `Crypt` borítéka egy JSON-objektum (`iv`, `value`, `mac`,
 * `tag`), a MAC pedig csak az `iv`+`value` fölött van — a boríték tehát szabadon
 * ÚJRASZERIALIZÁLHATÓ (kulcssorrend, whitespace, extra kulcs), és minden változat érvényes
 * marad. Mérve: 200 variánsból 200. Plusz a `Crypt` IV-t használ, tehát ugyanarra az igényre
 * két kiadás eleve két különböző bájtsort ad.
 *
 * Ezért a tokenből NINCS „egyetlen érvényes alak", és minden fék (rate-limit, dedup) a
 * `fingerprint()`-re kulcsol — az IGÉNYRE, nem a bájtokra. Aki ezt bájtokra kulcsolná, a
 * fékét születetten megkerülhetőre írná.
 *
 * ## A FORMÁTUM VERZIÓJA (`v`) — és miért nincs átmeneti ág
 *
 * A `p` bevezetése MEGVÁLTOZTATTA a wire-formátumot: a korábbi, `p` nélküli token ma
 * érvénytelen. Élő telepítésen ez CSENDES kár lett volna — a `/u/` szándékosan nem ad
 * orákulumot, tehát a címzett a „leiratkoztál" oldalt látja, miközben semmi nem íródik, és a
 * következő kampányt megkapja (GDPR 21., golden rule 4).
 *
 * Átmeneti ág mégis NINCS, és ez tudatos döntés: a ventMail-nek nincs éles telepítése (az
 * M1 fejlesztés alatt van, a `ventmail` OIDC-kliens regisztrációja is nyitott emberi lépés),
 * tehát NINCS kiküldött levél, amiben régi token utazna. Egy visszafelé-kompatibilis ág
 * viszont épp azt a tokent fogadná el, amit ez a kör bizonyítottan veszélyesnek talált.
 *
 * Ami ELŐFELTÉTELE minden JÖVŐBELI formátumváltásnak:
 *  - a `v` mező, hogy a változás detektálható legyen (ez itt van, most);
 *  - a beégetett KAT-vektor (`UnsubscribeTokenVectorsTest`), ami a wire-formátumot RÖGZÍTI:
 *    egy néma formátumváltás onnantól a CI-ban bukik el, nem az ügyfél postafiókjában.
 *
 * ## KULCSROTÁCIÓ — `kid` helyett `APP_PREVIOUS_KEYS`
 *
 * A tokenben SZÁNDÉKOSAN nincs kulcs-azonosító. Nem is kell: a Laravel `Crypt` a
 * `config('app.previous_keys')` listát VÉGIGPRÓBÁLJA a megfejtésnél, tehát a rotáció
 * átfedéses ellenőrzést kap a keretrendszertől. Méréssel igazolt: a régi kulcsot a
 * `previous_keys`-be helyezve a korábban kiadott token továbbra is megfejtődik, nélküle
 * `null` (`TokenKeyRotationTest`).
 *
 * A KOCKÁZAT ezért nem kriptográfiai, hanem ÜZEMELTETÉSI: aki az `APP_KEY`-t a régi
 * kulcs megőrzése nélkül cseréli, minden kiküldött levél leiratkozó linkjét elnémítja —
 * és némán, mert ez a végpont nem ad orákulumot. Az `.env.example` ezért írja le a
 * rotáció menetét, és ezért van rá teszt.
 */
final class UnsubscribeTokens
{
    /** A token CÉLJA. Az `APP_KEY` közös; a cél-elválasztás a payloadon belül van. */
    public const PURPOSE = 'unsub';

    /**
     * A FORMÁTUM verziója.
     *
     * Emelése azt jelenti, hogy a korábban kiküldött levelek leiratkozó linkje elhal — tehát
     * emelni CSAK átmeneti ággal együtt szabad. A `parse()` a beégetett KAT-vektorral együtt
     * őrzi, hogy ez ne történhessen meg észrevétlenül.
     */
    public const VERSION = 1;

    public function issue(int $workspaceId, string $messageUuid, string $recipient): string
    {
        $payload = json_encode([
            'p' => self::PURPOSE,
            'v' => self::VERSION,
            'w' => $workspaceId,
            'm' => $messageUuid,
            'r' => $recipient,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return strtr(Crypt::encryptString((string) $payload), '+/=', '-_~');
    }

    /** @return array{workspaceId: int, messageUuid: string, recipient: string}|null */
    public function parse(string $token): ?array
    {
        $base64 = strtr($token, '-_~', '+/=');

        $binary = base64_decode($base64, true);

        // KANONIKUS alak: dekódol, visszakódol, bájtra egyeztet.
        if ($binary === false || base64_encode($binary) !== $base64) {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($base64), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['p'], $decoded['w'], $decoded['m'], $decoded['r'])) {
            return null;
        }

        // A CÉL kötelező és pontos: egy nyomkövetési token itt NEM érvényes.
        if ($decoded['p'] !== self::PURPOSE) {
            return null;
        }

        // A VERZIÓ is pontos. Ismeretlen verziót nem próbálunk „valahogy" értelmezni: a
        // félig megértett claim alapján leiratkoztatni rosszabb, mint nem leiratkoztatni.
        if (($decoded['v'] ?? null) !== self::VERSION) {
            return null;
        }

        return [
            'workspaceId' => (int) $decoded['w'],
            'messageUuid' => (string) $decoded['m'],
            'recipient' => (string) $decoded['r'],
        ];
    }

    /**
     * Az igény KANONIKUS kulcsa — ebből képezünk rate-limit-kulcsot.
     *
     * SOHA nem a token bájtjaiból: a `Crypt`-boríték újraszerializálható és IV-vel változik,
     * tehát ugyanarra az igényre végtelen sok érvényes bájtsor létezik. Bájtokra kulcsolva a
     * fék egy `json_encode`-dal megkerülhető lenne.
     */
    public function fingerprint(string $token): ?string
    {
        $claim = $this->parse($token);

        if ($claim === null) {
            return null;
        }

        return hash('sha256', implode('|', [
            self::PURPOSE,
            $claim['workspaceId'],
            $claim['messageUuid'],
            $claim['recipient'],
        ]));
    }
}
