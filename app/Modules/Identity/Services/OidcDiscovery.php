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
                $provider = (string) config('oidc.provider_url');
                $url = $provider.'/.well-known/openid-configuration';
                $response = Http::acceptJson()->timeout(5)->connectTimeout(3)->get($url);

                if (! $response->successful()) {
                    throw new RuntimeException("A ventSSO discovery nem érhető el: {$url}");
                }

                return $this->assertTrustworthy($response->json(), $provider);
            }
        );
    }

    /**
     * A discovery-dokumentum KIKÖTÉSE a konfigurált providerhez.
     *
     * Enélkül az `iss`-ellenőrzés önhivatkozó: aki a discovery-választ befolyásolja (pl.
     * cache-poisoning a megosztott Redisen vagy plain HTTP provider-URL), az egyszerre
     * adja meg a „megbízható" kibocsátót ÉS a „megbízható" aláíró kulcsot, és tetszőleges
     * `sub`-bal beléptethet. (OIDC Discovery 1.0 §4.3 az issuer-egyezést kötelezővé teszi.)
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function assertTrustworthy(array $document, string $provider): array
    {
        if (($document['issuer'] ?? null) !== $provider) {
            throw new RuntimeException('A discovery issuer nem egyezik a konfigurált providerrel.');
        }

        $providerHost = parse_url($provider, PHP_URL_HOST);
        $allowInsecure = (bool) config('oidc.allow_insecure_transport') && app()->environment('local', 'testing');

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $endpoint) {
            $url = $document[$endpoint] ?? throw new RuntimeException("Hiányzó OIDC végpont: {$endpoint}");

            $scheme = parse_url((string) $url, PHP_URL_SCHEME);

            if ($scheme !== 'https' && ! $allowInsecure) {
                throw new RuntimeException("Nem titkosított OIDC végpont: {$endpoint}");
            }

            if (parse_url((string) $url, PHP_URL_HOST) !== $providerHost) {
                throw new RuntimeException("Idegen hoszton hirdetett OIDC végpont: {$endpoint}");
            }
        }

        return $document;
    }

    public function endpoint(string $name): string
    {
        $document = $this->document();

        return $document[$name] ?? throw new RuntimeException("Hiányzó OIDC végpont: {$name}");
    }

    /** A kibocsátó MINDIG a konfigurált provider — sosem a dokumentum önbevallása. */
    public function issuer(): string
    {
        return (string) config('oidc.provider_url');
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
                $response = Http::acceptJson()->timeout(5)->connectTimeout(3)->get($this->endpoint('jwks_uri'));

                if (! $response->successful()) {
                    throw new RuntimeException('A ventSSO JWKS nem érhető el.');
                }

                // Csak aláíró kulcs jöhet szóba: egy `use: enc` kulcs nem válhat
                // aláírás-ellenőrző kulccsá (a php-jwt nem szűr `use`-ra).
                return array_values(array_filter(
                    $response->json('keys', []),
                    static fn (array $key): bool => ($key['use'] ?? 'sig') === 'sig'
                        && in_array($key['alg'] ?? 'RS256', (array) config('oidc.allowed_algorithms'), true)
                ));
            }
        );
    }

    public function forget(): void
    {
        Cache::forget(self::DISCOVERY_CACHE_KEY);
        Cache::forget(self::JWKS_CACHE_KEY);
    }
}
