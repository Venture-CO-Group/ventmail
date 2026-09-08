<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * A PREFERENCE CENTER linkjének tokenje (55. kritérium).
 *
 * Ugyanaz a forma, mint a másik három tokennél — cél-elválasztás (`p`), verzió (`v`),
 * kanonikus base64, igény-lenyomat a fékhez —, mert az `APP_KEY` közös, és cél nélkül a
 * négy token felcserélhető lenne.
 *
 * A LEJÁRAT hosszabb, mint a megerősítőé (alap 90 nap): a címzett a levélben lévő linket
 * hetekkel később is joggal használja a beállításai módosítására. De nem végtelen: egy
 * évekkel korábbi levélből előkerülő link már nem a jelenlegi szándékot tükrözi, és a
 * preference center ÍR — listákat kapcsol ki-be.
 */
final class PreferenceTokens
{
    public const PURPOSE = 'prefs';

    public const VERSION = 1;

    public function issue(int $workspaceId, string $email): string
    {
        $payload = json_encode([
            'p' => self::PURPOSE,
            'v' => self::VERSION,
            'w' => $workspaceId,
            'e' => $email,
            'x' => now()->addHours(max(1, (int) config('consent.preference_ttl_hours', 2160)))->getTimestamp(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return strtr(Crypt::encryptString((string) $payload), '+/=', '-_~');
    }

    /** @return array{workspaceId: int, email: string}|null */
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

        if (! is_array($decoded) || ! isset($decoded['p'], $decoded['v'], $decoded['w'], $decoded['e'], $decoded['x'])) {
            return null;
        }

        if ($decoded['p'] !== self::PURPOSE || $decoded['v'] !== self::VERSION) {
            return null;
        }

        if ((int) $decoded['x'] < now()->getTimestamp()) {
            return null;
        }

        return ['workspaceId' => (int) $decoded['w'], 'email' => (string) $decoded['e']];
    }

    public function fingerprint(string $token): ?string
    {
        $claim = $this->parse($token);

        return $claim === null
            ? null
            : hash('sha256', implode('|', [self::PURPOSE, $claim['workspaceId'], $claim['email']]));
    }
}
