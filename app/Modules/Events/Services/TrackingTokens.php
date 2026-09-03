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
 */
final class TrackingTokens
{
    public function issue(int $workspaceId, string $messageUuid, string $recipient, ?string $destination = null): string
    {
        $payload = json_encode([
            'w' => $workspaceId,
            'm' => $messageUuid,
            'r' => $recipient,
            'd' => $destination,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return strtr(Crypt::encryptString((string) $payload), '+/=', '-_~');
    }

    public function parse(string $token): ?TrackingClaim
    {
        try {
            $decoded = json_decode(
                Crypt::decryptString(strtr($token, '-_~', '+/=')),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (DecryptException|JsonException) {
            // Hamisított vagy elrontott token. A hívó ettől még NEM ad más választ, mint a
            // jóra: a különbség orákulum lenne a token érvényességére.
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['w'], $decoded['m'], $decoded['r'])) {
            return null;
        }

        return new TrackingClaim(
            workspaceId: (int) $decoded['w'],
            messageUuid: (string) $decoded['m'],
            recipient: (string) $decoded['r'],
            destination: isset($decoded['d']) ? (string) $decoded['d'] : null,
        );
    }
}
