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
 * A KANONIKUS ALAK itt is kötelező: a base64 nem-szigorú dekódolása belső `=`-t átugorna,
 * és egyetlen elkapott tokenből több ezer variáns lenne gyártható. Leiratkozásnál ez azért
 * súlyos, mert a végpont ÍR (suppression-listára tesz), és a rate-limit a variánsokkal
 * megkerülhető lenne.
 */
final class UnsubscribeTokens
{
    public function issue(int $workspaceId, string $messageUuid, string $recipient): string
    {
        $payload = json_encode([
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

        if (! is_array($decoded) || ! isset($decoded['w'], $decoded['m'], $decoded['r'])) {
            return null;
        }

        return [
            'workspaceId' => (int) $decoded['w'],
            'messageUuid' => (string) $decoded['m'],
            'recipient' => (string) $decoded['r'],
        ];
    }
}
