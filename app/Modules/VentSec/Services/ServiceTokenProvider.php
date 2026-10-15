<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Services;

use App\Modules\Identity\Services\OidcDiscovery;
use App\Modules\VentSec\Support\SecureUrl;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * m2m token a ventSSO-tól (client_credentials; `resource` = a ventSec, scope = scan + ingest).
 *
 * A ventMail CSAK fogyasztó: a tokent a ventSSO adja ki, itt csak kérjük és tároljuk. A cache
 * titkosított (a Redis-be nem kerül nyers bearer), az élettartama a lejárat előtt 60 másodperccel
 * ér véget — az `expires_in` és a JWT `exp` közül a korábbi számít. Bármilyen hiba `null`: a
 * hívó fail-closed (szkenner: `unavailable`; esemény: újrapróbálás). A kliens-titok sehova nem
 * kerül logba.
 */
final class ServiceTokenProvider
{
    private const CACHE_KEY = 'ventsec:m2m-token';

    private const SAFETY_MARGIN = 60;

    public function token(): ?string
    {
        $clientId = (string) config('ventsec.client_id');
        $secret = (string) config('ventsec.client_secret');

        if ($clientId === '' || $secret === '') {
            return null;
        }

        $cached = $this->cached();

        if ($cached !== null) {
            return $cached;
        }

        $url = $this->tokenUrl();

        if ($url === null || ! SecureUrl::ok($url)) {
            Log::warning('ventsec.token.endpoint_unusable');

            return null;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $secret)
                ->acceptJson()
                ->timeout(10)->connectTimeout(5)
                ->withOptions(['allow_redirects' => false])
                ->post($url, array_filter([
                    'grant_type' => 'client_credentials',
                    'scope' => (string) config('ventsec.scope'),
                    'resource' => (string) config('ventsec.resource'),
                ], fn (string $value) => $value !== ''));
        } catch (Throwable $e) {
            Log::warning('ventsec.token.request_failed', ['error' => $e::class]);

            return null;
        }

        $json = $response->successful() ? $response->json() : null;
        $token = is_array($json) ? ($json['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            Log::warning('ventsec.token.rejected', ['status' => $response->status()]);

            return null;
        }

        $this->remember($token, is_numeric($json['expires_in'] ?? null) ? (int) $json['expires_in'] : null);

        return $token;
    }

    /** Egy 401 után a cache-elt token nem használható újra. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function tokenUrl(): ?string
    {
        $configured = (string) config('ventsec.token_url');

        if ($configured !== '') {
            return $configured;
        }

        try {
            return (string) (app(OidcDiscovery::class)->document()['token_endpoint'] ?? '') ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function cached(): ?string
    {
        $sealed = Cache::get(self::CACHE_KEY);

        if (! is_string($sealed)) {
            return null;
        }

        try {
            return Crypt::decryptString($sealed);
        } catch (DecryptException) {
            return null;
        }
    }

    private function remember(string $token, ?int $expiresIn): void
    {
        $remaining = $expiresIn;
        $exp = $this->jwtExpiry($token);

        if ($exp !== null) {
            $remaining = $remaining === null ? $exp - now()->getTimestamp() : min($remaining, $exp - now()->getTimestamp());
        }

        $ttl = ($remaining ?? 0) - self::SAFETY_MARGIN;

        if ($ttl > 0) {
            Cache::put(self::CACHE_KEY, Crypt::encryptString($token), $ttl);
        }
    }

    /** A JWT `exp`-je — csak az élettartamhoz; az aláírást a ventSec ellenőrzi. */
    private function jwtExpiry(string $token): ?int
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), false), true);

        return is_array($payload) && isset($payload['exp']) && is_numeric($payload['exp']) ? (int) $payload['exp'] : null;
    }
}
