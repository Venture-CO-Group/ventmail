<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A ventSSO discovery-dokumentumának és JWKS-ének betöltése, cache-eléssel.
 *
 * Kulcsrotáció: ha az ID-token `kid`-je nincs a cache-elt JWKS-ben, EGYSZER ürítünk és
 * újratöltünk. Enélkül a ventSSO minden kulcsrotációja néma login-hibát okozna, a
 * korlátlan újratöltés viszont DoS-felület lenne az IdP felé.
 */
final class OidcDiscovery
{
    private const DISCOVERY_CACHE_KEY = 'oidc:discovery';

    private const JWKS_CACHE_KEY = 'oidc:jwks';

    /** @return array<string, mixed> */
    public function document(): array
    {
        return Cache::remember(
            self::DISCOVERY_CACHE_KEY,
            (int) config('oidc.discovery_cache_ttl'),
            function (): array {
                $url = config('oidc.provider_url').'/.well-known/openid-configuration';
                $response = Http::acceptJson()->get($url);

                if (! $response->successful()) {
                    throw new RuntimeException("A ventSSO discovery nem érhető el: {$url}");
                }

                return $response->json();
            }
        );
    }

    public function endpoint(string $name): string
    {
        $document = $this->document();

        return $document[$name] ?? throw new RuntimeException("Hiányzó OIDC végpont: {$name}");
    }

    public function issuer(): string
    {
        return $this->document()['issuer'] ?? config('oidc.provider_url');
    }

    /**
     * A JWKS kulcsai. `$forceRefresh` esetén a cache-t megkerülve tölt újra.
     *
     * @return array<int, array<string, mixed>>
     */
    public function keys(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget(self::JWKS_CACHE_KEY);
        }

        return Cache::remember(
            self::JWKS_CACHE_KEY,
            (int) config('oidc.jwks_cache_ttl'),
            function (): array {
                $response = Http::acceptJson()->get($this->endpoint('jwks_uri'));

                if (! $response->successful()) {
                    throw new RuntimeException('A ventSSO JWKS nem érhető el.');
                }

                return $response->json('keys', []);
            }
        );
    }

    public function forget(): void
    {
        Cache::forget(self::DISCOVERY_CACHE_KEY);
        Cache::forget(self::JWKS_CACHE_KEY);
    }
}
