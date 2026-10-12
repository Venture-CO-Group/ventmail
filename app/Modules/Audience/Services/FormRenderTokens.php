<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * A HOSZTOLT ŰRLAP renderelési tokenje (v1.1 · 5. modul) — szkript nélküli time-trap.
 *
 * A beágyazott kód a kitöltési időt a böngészőben méri (`_elapsed`), ami a kliens szava. A
 * hosztolt oldalon nincs szkript (szigorú CSP), ezért a szerver az oldal kiszolgálásakor egy
 * TITKOSÍTOTT, az űrlaphoz kötött időbélyeget ad (`_rt`); a beküldéskor ebből számoljuk az
 * eltelt időt. A token nem hamisítható (APP_KEY), más űrlapra nem vihető át, és egy napnál
 * régebbi token nem érvényes (egy régi, gyorsítótárazott oldalról jövő beküldés így nem kap
 * „nagyon lassú ember" felmentést örökre).
 */
final class FormRenderTokens
{
    private const PURPOSE = 'form-render';

    private const MAX_AGE = 86400;

    public function issue(string $formUuid): string
    {
        return Crypt::encryptString(json_encode(['p' => self::PURPOSE, 'f' => $formUuid, 't' => now()->getTimestamp()], JSON_THROW_ON_ERROR));
    }

    /**
     * Az eltelt másodpercek, vagy `null`, ha a token érvénytelen (hamis, más űrlapé, lejárt).
     */
    public function elapsed(string $token, string $formUuid): ?int
    {
        if ($token === '' || strlen($token) > 2048) {
            return null;
        }

        try {
            $claim = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($claim) || ($claim['p'] ?? null) !== self::PURPOSE || ($claim['f'] ?? null) !== $formUuid || ! is_int($claim['t'] ?? null)) {
            return null;
        }

        $elapsed = now()->getTimestamp() - $claim['t'];

        return $elapsed < 0 || $elapsed > self::MAX_AGE ? null : $elapsed;
    }
}
