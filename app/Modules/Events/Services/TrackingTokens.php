<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Modules\Events\Data\TrackingClaim;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * A nyomkövetési link tokenje (23. és 26. kritérium: opened / clicked).
 *
 * A token TITKOSÍTVA hordozza a címzettet és a klikk célját, nem aláírva. A különbség
 * lényeges:
 *
 *  - a CÍMZETT e-mail-címe személyes adat, a link viszont proxykon, vírusszűrőkön és
 *    böngésző-előzményeken megy át — aláírt, de olvasható tokenben ott ülne nyíltan;
 *  - a KLIKK CÉLJA a tokenen BELÜL van, nem a query stringben. Ha kívül lenne, bárki
 *    átírhatná, és a `ventmail.app/t/c/…` egy közönséges NYÍLT ÁTIRÁNYÍTÓ lenne — épp az a
 *    domain, aminek a jó híre a kézbesíthetőségünk alapja.
 *
 * A `Crypt` egyszerre ad titkosítást és MAC-et, tehát a hamisított token nem fejthető meg.
 * URL-biztos alakra fordítjuk: a base64 `+/=` karakterei útvonal-szegmensben nem használhatók.
 *
 * A TOKEN KANONIKUS — és ez nem szépészeti kérdés.
 *
 * A base64 nem-szigorú dekódolása átugorja a `=` karaktert a string BELSEJÉBEN is. Mivel a
 * `~` nálunk `=`-re fordul, egyetlen elkapott tokenből TÖBB EZER olyan variáns gyártható, ami
 * ugyanarra az igényre fejtődik meg. Ha bármi (dedup, rate-limit, replay-védelem) a token
 * BÁJTJAIRA kulcsol, azt ezzel meg lehet kerülni — mérve: egy `~` beszúrása 370 pozícióban
 * érvényes maradt.
 *
 * Ezért a `parse()` a KANONIKUS base64-alakot követeli meg: dekódol, majd VISSZAKÓDOL, és
 * bájtra egyeztet. Ez elutasítja a belső `=`-t, a levágott paddinget és a fel nem használt
 * záró bitek nem nulla variánsait is — a szigorú `base64_decode` önmagában az utóbbi kettőt
 * átengedi.
 *
 * Amit viszont a kanonikus alak NEM zár: a Laravel `Crypt` borítéka egy JSON-objektum (`iv`,
 * `value`, `mac`, `tag`), és a MAC csak az `iv`+`value` fölött van — a boríték tehát szabadon
 * ÚJRASZERIALIZÁLHATÓ (kulcssorrend, whitespace, extra kulcs), és minden változat érvényes
 * marad. Plusz a `Crypt` IV-t használ, tehát ugyanarra az igényre két kiadás eleve két
 * különböző bájtsort ad.
 *
 * Vagyis a tokenből NINCS „egyetlen érvényes alak" — sem a belső `=`-t zárva, sem anélkül.
 * Ezért minden fék (dedup, rate-limit) a `fingerprint()`-re kulcsol: az IGÉNYRE, nem a
 * bájtokra. A kanonikus alak megkövetelése ettől nem fölösleges (a hibás bemenetet korán,
 * egyértelműen utasítja el), csak épp nem EZ a fékek védelme.
 *
 * ## A CÉL a tokenen BELÜL van (`p`)
 *
 * Az `APP_KEY` közös: minden azzal kiadott token megfejthető minden olyan kóddal, ami
 * ugyanazt a kulcsot használja. A `UnsubscribeTokens` ugyanezt a `w`/`m`/`r` hármast adja ki
 * ugyanabban a borítékban — cél-elválasztás nélkül a NYOMKÖVETÉSI token érvényes LEIRATKOZÓ
 * tokenné vált (mérve: egy klikk-tokennel a `POST /u/{token}` felvette a címzettet a
 * suppression-listára, visszavonhatatlanul). A nyomkövetési token szándékosan alacsony
 * értékű és széles felületen szétszóródó — a leiratkozó viszont írási képesség. A `p` a
 * titkosított payloadon belül van, tehát a MAC védi.
 */
final class TrackingTokens
{
    /** A token CÉLJA. Az `APP_KEY` közös; a cél-elválasztás a payloadon belül van. */
    public const PURPOSE = 'track';

    /**
     * A FORMÁTUM verziója.
     *
     * Emelése azt jelenti, hogy a korábban kiküldött levelek nyomkövetési linkje 404-et ad,
     * és a `opened`/`clicked` események elvesznek — emelni CSAK átmeneti ággal szabad.
     */
    public const VERSION = 1;

    public function issue(int $workspaceId, string $messageUuid, string $recipient, string $host, ?string $destination = null): string
    {
        $payload = json_encode([
            'p' => self::PURPOSE,
            'v' => self::VERSION,
            'w' => $workspaceId,
            'm' => $messageUuid,
            'r' => $recipient,
            'h' => strtolower($host),
            'd' => $destination,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return strtr(Crypt::encryptString((string) $payload), '+/=', '-_~');
    }

    public function parse(string $token): ?TrackingClaim
    {
        $base64 = strtr($token, '-_~', '+/=');

        // KANONIKUS base64: dekódol, visszakódol, bájtra egyeztet. A puszta szigorú
        // dekódolás átengedné a levágott paddinget és a fel nem használt záró bitek nem nulla
        // variánsait — mindkettő ugyanarra az igényre fejtődne meg más bájtsorból.
        $binary = base64_decode($base64, true);

        if ($binary === false || base64_encode($binary) !== $base64) {
            return null;
        }

        try {
            $decoded = json_decode(
                Crypt::decryptString($base64),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (DecryptException|JsonException) {
            // Hamisított vagy elrontott token. A hívó ettől még NEM ad más választ, mint a
            // jóra: a különbség orákulum lenne a token érvényességére.
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['p'], $decoded['w'], $decoded['m'], $decoded['r'], $decoded['h'])) {
            return null;
        }

        // A CÉL kötelező és pontos: egy leiratkozó token itt NEM érvényes.
        if ($decoded['p'] !== self::PURPOSE) {
            return null;
        }

        // A VERZIÓ is pontos — ismeretlen verziót nem értelmezünk félig.
        if (($decoded['v'] ?? null) !== self::VERSION) {
            return null;
        }

        return new TrackingClaim(
            workspaceId: (int) $decoded['w'],
            messageUuid: (string) $decoded['m'],
            recipient: (string) $decoded['r'],
            host: (string) $decoded['h'],
            destination: isset($decoded['d']) ? (string) $decoded['d'] : null,
        );
    }

    /**
     * Az igény KANONIKUS kulcsa — ebből képezünk dedup- és rate-limit-kulcsot.
     *
     * SOHA nem a token bájtjaiból: a `Crypt`-boríték újraszerializálható és IV-vel változik,
     * tehát ugyanarra az igényre végtelen sok érvényes bájtsor létezik. Bájtokra kulcsolva a
     * fék egy `json_encode`-dal megkerülhető lenne.
     */
    public function fingerprint(TrackingClaim $claim): string
    {
        return hash('sha256', implode('|', [
            self::PURPOSE,
            $claim->workspaceId,
            $claim->messageUuid,
            $claim->recipient,
            $claim->host,
            $claim->destination ?? '',
        ]));
    }
}
