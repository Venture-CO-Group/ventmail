<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Services;

use App\Modules\VentSec\Support\SecureUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A ventSec közzétett ítélet-aláíró kulcsai (`/.well-known/ventsec-verdict-keys.json`, JWK/OKP).
 *
 * Cache-elt; ismeretlen `kid` esetén (kulcsrotáció) EGYSZER újratölt, de legfeljebb percenként —
 * különben egy hamis `key_id` a ventSec felé pörgetné a végpontot. Csak EdDSA/OKP/Ed25519,
 * 32 bájtos kulcs fogadható el, és a `kid`-nek a kulcs SHA-256-ának első 16 hex jegyével kell
 * egyeznie (a ventSec így képzi) — a hibás bejegyzés elmarad, nem az egész dokumentum.
 */
final class VerdictKeyStore
{
    private const CACHE_KEY = 'ventsec:verdict-keys';

    private const REFETCH_COOLDOWN = 'ventsec:verdict-keys:refetch';

    /** A nyers, 32 bájtos nyilvános kulcs, vagy null. */
    public function find(string $keyId): ?string
    {
        $keys = Cache::get(self::CACHE_KEY);
        $fetched = false;

        if (! is_array($keys)) {
            $keys = $this->fetch();
            $fetched = true;
        }

        if (isset($keys[$keyId])) {
            return $keys[$keyId];
        }

        if (! $fetched && Cache::add(self::REFETCH_COOLDOWN, 1, 60)) {
            $keys = $this->fetch();
        }

        return $keys[$keyId] ?? null;
    }

    /** @return array<string, string> kid → nyers kulcs; hiba esetén üres (nem cache-elt) */
    private function fetch(): array
    {
        $url = (string) config('ventsec.keys_url') ?: rtrim((string) config('ventsec.scan_url'), '/').'/.well-known/ventsec-verdict-keys.json';

        if (! SecureUrl::ok($url)) {
            return [];
        }

        try {
            $response = Http::acceptJson()->timeout(5)->connectTimeout(3)->withOptions(['allow_redirects' => false])->get($url);
        } catch (Throwable $e) {
            Log::warning('ventsec.keys.fetch_failed', ['error' => $e::class]);

            return [];
        }

        $document = $response->successful() ? $response->json() : null;

        if (! is_array($document) || ! is_array($document['keys'] ?? null)) {
            Log::warning('ventsec.keys.unusable', ['status' => $response->status()]);

            return [];
        }

        $keys = [];

        foreach ($document['keys'] as $jwk) {
            $raw = $this->parse($jwk);

            if ($raw !== null) {
                $keys[(string) $jwk['kid']] = $raw;
            }
        }

        if ($keys !== []) {
            Cache::put(self::CACHE_KEY, $keys, (int) config('ventsec.keys_cache_ttl'));
        }

        return $keys;
    }

    private function parse(mixed $jwk): ?string
    {
        if (! is_array($jwk) || ($jwk['kty'] ?? null) !== 'OKP' || ($jwk['crv'] ?? null) !== 'Ed25519'
            || ! is_string($jwk['kid'] ?? null) || ! is_string($jwk['x'] ?? null)) {
            return null;
        }

        $raw = base64_decode(strtr($jwk['x'], '-_', '+/'), true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }

        return hash_equals(substr(hash('sha256', $raw), 0, 16), $jwk['kid']) ? $raw : null;
    }
}
