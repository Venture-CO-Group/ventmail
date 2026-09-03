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
 * Ezért a `parse()` SZIGORÚAN dekódol, ÉS visszaellenőrzi, hogy a kapott token pontosan az az
 * alak-e, amit mi adnánk ki ugyanarra az adatra. Egy igény = egy token.
 */
final class TrackingTokens
{
    public function issue(int $workspaceId, string $messageUuid, string $recipient, string $host, ?string $destination = null): string
    {
        $payload = json_encode([
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

        // SZIGORÚ base64: a nem-szigorú változat a string belsejébe szúrt `=`-t (nálunk `~`)
        // csendben átugorja, és a malleált token ugyanarra az igényre fejtődne meg.
        if (base64_decode($base64, true) === false) {
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

        if (! is_array($decoded) || ! isset($decoded['w'], $decoded['m'], $decoded['r'], $decoded['h'])) {
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
     * SOHA nem a token bájtjaiból: abból több ezer érvényes variáns gyártható ugyanarra az
     * igényre, és minden ilyen fék megkerülhető lenne.
     */
    public function fingerprint(TrackingClaim $claim): string
    {
        return hash('sha256', implode('|', [
            $claim->workspaceId,
            $claim->messageUuid,
            $claim->recipient,
            $claim->host,
            $claim->destination ?? '',
        ]));
    }
}
