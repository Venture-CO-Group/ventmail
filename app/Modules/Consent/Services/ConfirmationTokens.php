<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * A DOUBLE OPT-IN megerősítő link tokenje (50. kritérium).
 *
 * Ugyanaz a minta, mint a leiratkozó és a nyomkövetési tokennél, ugyanazokkal a zárakkal —
 * és ez nem másolás, hanem a négy review-körben megtanult forma:
 *
 *  - **cél-elválasztás (`p`)**: az `APP_KEY` közös, tehát cél nélkül egy leiratkozó vagy
 *    nyomkövetési token ÉRVÉNYES megerősítő tokenné válna. Ez itt különösen súlyos lenne: a
 *    megerősítés JOGALAPOT teremt, tehát egy elkapott nyomkövetési tokennel bárki
 *    „hozzájárulást" gyárthatna valaki más nevében;
 *  - **verzió (`v`)**: a formátumváltás detektálható legyen, mert a levél hónapokig él;
 *  - **kanonikus base64**: a hibás bemenet korán, egyértelműen elhasal;
 *  - **`fingerprint()`**: a fékek az IGÉNYRE kulcsolnak, sosem a token bájtjaira (a
 *    `Crypt`-boríték újraszerializálható és IV-vel változik).
 *
 * LEJÁRAT viszont VAN, szemben a leiratkozó tokennel — és a különbség szándékos. A
 * leiratkozás régi levélből is legitim; a megerősítés nem: egy hónapokkal későbbi kattintás
 * nem bizonyítja, hogy a felhasználó MOST is akarja. A GDPR szerinti hozzájárulás
 * „konkrét és tájékozott", és egy fél éves link ennek nem felel meg.
 */
final class ConfirmationTokens
{
    public const PURPOSE = 'optin';

    public const VERSION = 1;

    /** A megerősítő link élettartama. Konfigurálható, de nem végtelen. */
    public function ttlHours(): int
    {
        return max(1, (int) config('consent.confirmation_ttl_hours', 168));
    }

    public function issue(int $workspaceId, string $email, string $channel, ?string $listUuid = null): string
    {
        $payload = json_encode([
            'p' => self::PURPOSE,
            'v' => self::VERSION,
            'w' => $workspaceId,
            'e' => $email,
            'c' => $channel,
            'l' => $listUuid,
            // A LEJÁRAT a tokenben van, nem az adatbázisban: így nem kell egy külön táblát
            // vezetni és takarítani, és a token önmagában hordozza az érvényességét.
            'x' => now()->addHours($this->ttlHours())->getTimestamp(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return strtr(Crypt::encryptString((string) $payload), '+/=', '-_~');
    }

    /** @return array{workspaceId: int, email: string, channel: string, listUuid: ?string}|null */
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

        if (! is_array($decoded) || ! isset($decoded['p'], $decoded['v'], $decoded['w'], $decoded['e'], $decoded['c'], $decoded['x'])) {
            return null;
        }

        if ($decoded['p'] !== self::PURPOSE || $decoded['v'] !== self::VERSION) {
            return null;
        }

        // LEJÁRT link: ugyanaz a válasz, mint a hamisra. A különbség orákulum lenne arra,
        // hogy egy cím szerepel-e a rendszerben.
        if ((int) $decoded['x'] < now()->getTimestamp()) {
            return null;
        }

        return [
            'workspaceId' => (int) $decoded['w'],
            'email' => (string) $decoded['e'],
            'channel' => (string) $decoded['c'],
            'listUuid' => isset($decoded['l']) ? (string) $decoded['l'] : null,
        ];
    }

    /** Az igény kanonikus kulcsa — a rate-limit ebből képez kulcsot, nem a bájtokból. */
    public function fingerprint(string $token): ?string
    {
        $claim = $this->parse($token);

        if ($claim === null) {
            return null;
        }

        return hash('sha256', implode('|', [
            self::PURPOSE,
            $claim['workspaceId'],
            $claim['email'],
            $claim['channel'],
        ]));
    }
}
