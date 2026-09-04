<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

/**
 * SPF / DKIM / DMARC eredmény és spam-pontszám a fejlécekből (38. kritérium).
 *
 * A `Authentication-Results` fejlécet a FOGADÓ ÍRJA — vagyis mi. Amit a küldő tesz bele, az
 * hazugság: bárki írhat a levelébe `dkim=pass`-t. Ezért CSAK a legelső, SAJÁT
 * `Authentication-Results` fejlécet olvassuk, azt is a saját `authserv-id`-nkkal egyeztetve;
 * az idegen fejléceket figyelmen kívül hagyjuk.
 *
 * A tényleges SPF/DKIM ellenőrzést a fogadó MTA végzi és írja be — a mi dolgunk itt az
 * OLVASÁS és a továbbadás, hogy a `forward(url)` payload alapján a fogadó dönthessen.
 */
final class MailAuthenticationReader
{
    /** @param array<string, string> $headers */
    public function spf(array $headers): ?string
    {
        return $this->result($headers, 'spf');
    }

    /** @param array<string, string> $headers */
    public function dkim(array $headers): ?string
    {
        return $this->result($headers, 'dkim');
    }

    /** @param array<string, string> $headers */
    public function dmarc(array $headers): ?string
    {
        return $this->result($headers, 'dmarc');
    }

    /**
     * A spam-pontszám a szűrőnk fejlécéből.
     *
     * @param  array<string, string>  $headers
     */
    public function spamScore(array $headers): ?float
    {
        $value = $headers['x-ventmail-spam-score'] ?? $headers['x-spam-score'] ?? null;

        return $value === null || ! is_numeric(trim($value)) ? null : (float) trim($value);
    }

    /** @param array<string, string> $headers */
    private function result(array $headers, string $method): ?string
    {
        $line = $headers['authentication-results'] ?? '';

        if ($line === '') {
            return null;
        }

        // A SAJÁT authserv-id: enélkül egy továbbított levélben ott lehet egy idegen
        // (vagy hamisított) `Authentication-Results`, és mi azt olvasnánk sajátunkként.
        $expected = (string) config('inbound.authserv_id');

        if ($expected !== '' && ! str_starts_with(trim($line), $expected)) {
            return null;
        }

        return preg_match('/\b'.preg_quote($method, '/').'=([a-z]+)/i', $line, $match) === 1
            ? strtolower($match[1])
            : null;
    }
}
