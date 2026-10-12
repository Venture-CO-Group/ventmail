<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * A „MEGNYITÁS BÖNGÉSZŐBEN" link tokenje (v1.1 · `{{ browser_url }}` merge-tag).
 *
 * Ugyanaz a modell, mint a leiratkozó tokené (`UnsubscribeTokens`): TITKOSÍTOTT (tehát
 * hamisíthatatlan ÉS olvashatatlan), cél-elválasztott (`p = web`), verziózott, és a
 * tenant-kontextus is belőle jön. A címzettet NEM tartalmazza: a webes nézet nem
 * személyre szabott (lásd `WebVersionController`).
 */
final class WebVersionTokens
{
    public const PURPOSE = 'web';

    public const VERSION = 1;

    public function issue(int $workspaceId, string $messageUuid): string
    {
        $payload = json_encode(['p' => self::PURPOSE, 'v' => self::VERSION, 'w' => $workspaceId, 'm' => $messageUuid]);

        return strtr(Crypt::encryptString((string) $payload), '+/=', '-_~');
    }

    /** @return array{workspaceId: int, messageUuid: string}|null */
    public function parse(string $token): ?array
    {
        $base64 = strtr($token, '-_~', '+/=');
        $binary = base64_decode($base64, true);

        if ($binary === false || base64_encode($binary) !== $base64) {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($base64), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($decoded) || ($decoded['p'] ?? null) !== self::PURPOSE || ($decoded['v'] ?? null) !== self::VERSION
            || ! isset($decoded['w'], $decoded['m'])) {
            return null;
        }

        return ['workspaceId' => (int) $decoded['w'], 'messageUuid' => (string) $decoded['m']];
    }
}
