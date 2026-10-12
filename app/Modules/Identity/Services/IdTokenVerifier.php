<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * ID-token ellenőrzés: aláírás (allowlistázott algoritmus, `kid` szerint), kibocsátó,
 * közönség (`aud` + `azp`), `sub`, lejárat és `nonce`.
 *
 * Kulcsrotáció: ha a token `kid`-je nincs a cache-elt JWKS-ben, EGYSZER újratöltünk.
 * Minden más hiba (lejárat, rossz aláírás, csonka JWT) NEM indít újratöltést — az
 * cache-stampede-et okozna a ventSSO ellen egy órakülönbség miatt is.
 */
final class IdTokenVerifier
{
    public function __construct(private readonly OidcDiscovery $discovery) {}

    /**
     * @return array<string, mixed> a token claimjei
     *
     * @throws AuthorizationException
     */
    public function verify(string $idToken, string $expectedNonce): array
    {
        $header = $this->header($idToken);

        $this->assertAlgorithm($header);

        $claims = $this->decode($idToken, $this->needsRefresh($header));

        $this->assertIssuer($claims);
        $this->assertAudience($claims);
        $this->assertSubject($claims);
        $this->assertNonce($claims, $expectedNonce);

        return $claims;
    }

    /**
     * A ventSSO ACCESS TOKENJÉNEK szerep-claimjei (Passport JWT, spec §5.7) — a ventSSO a
     * `resource_access` / `realm_access` szerepeket ide teszi, az ID-tokenbe nem.
     *
     * Ugyanaz a JWKS és algoritmus-allowlist, mint az ID-tokennél; az `aud` a mi
     * kliensünk, a `sub` az ID-tokené. Az access tokenben nincs `iss` (Passport), a
     * kibocsátót a JWKS-kulcs bizonyítja. Bármilyen eltérésnél ÜRES tömb jön — a szerep
     * hiánya fail-closed (a belépés tagság nélkül 403).
     *
     * @param  array<string, mixed>  $idClaims
     * @return array<string, mixed>
     */
    public function roleClaimsFromAccessToken(string $accessToken, array $idClaims): array
    {
        // CSAK a token-endpoint (TLS, kliens-hitelesített back-channel) válaszára hívható.
        try {
            $header = $this->header($accessToken);
            $this->assertAlgorithm($header);

            // A Passport access tokenjének fejlécében NINCS `kid`: ilyenkor a publikált,
            // allowlistázott aláíró kulcsokat egyenként próbáljuk — JWKS-újratöltés nélkül.
            $claims = (string) ($header['kid'] ?? '') === ''
                ? $this->decodeWithoutKid($accessToken, (string) $header['alg'])
                : $this->decode($accessToken, $this->needsRefresh($header));

            $this->assertAudience($claims);
        } catch (Throwable) {
            return [];
        }

        if (trim((string) ($claims['sub'] ?? '')) === '' || (string) $claims['sub'] !== (string) ($idClaims['sub'] ?? '')) {
            return [];
        }

        return array_intersect_key($claims, array_flip(['resource_access', 'realm_access']));
    }

    /** @return array<string, mixed> */
    private function decodeWithoutKid(string $token, string $alg): array
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = (int) config('oidc.leeway');

        try {
            foreach ($this->discovery->keys() as $jwk) {
                if (($jwk['use'] ?? 'sig') !== 'sig' || ($jwk['kty'] ?? '') !== 'RSA' && ($jwk['kty'] ?? '') !== 'EC') {
                    continue;
                }

                try {
                    $key = JWK::parseKey([...$jwk, 'alg' => $jwk['alg'] ?? $alg]);
                } catch (Throwable) {
                    continue;
                }

                if ($key === null || $key->getAlgorithm() !== $alg) {
                    continue;
                }

                try {
                    return (array) json_decode(json_encode(JWT::decode($token, $key)), associative: true);
                } catch (Throwable) {
                    continue;
                }
            }
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        throw new AuthorizationException('Az access token aláírása egyik publikált kulccsal sem ellenőrizhető.');
    }

    /** @return array<string, mixed> */
    private function header(string $idToken): array
    {
        $segments = explode('.', $idToken);

        if (count($segments) !== 3) {
            throw new AuthorizationException('Az ID-token szerkezete érvénytelen.');
        }

        $header = json_decode(JWT::urlsafeB64Decode($segments[0]), associative: true);

        if (! is_array($header)) {
            throw new AuthorizationException('Az ID-token fejléce nem értelmezhető.');
        }

        return $header;
    }

    /** @param array<string, mixed> $header */
    private function assertAlgorithm(array $header): void
    {
        $allowed = (array) config('oidc.allowed_algorithms');

        if (! in_array($header['alg'] ?? '', $allowed, true)) {
            throw new AuthorizationException('Az ID-token algoritmusa nem engedélyezett.');
        }
    }

    /**
     * Ismeretlen `kid` → egyszeri újratöltés. Ismert `kid` → a cache-elt kulcs elég.
     *
     * @param  array<string, mixed>  $header
     */
    private function needsRefresh(array $header): bool
    {
        $kid = (string) ($header['kid'] ?? '');

        if ($kid === '') {
            return false;
        }

        foreach ($this->discovery->keys() as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function decode(string $idToken, bool $forceRefresh): array
    {
        try {
            $keys = JWK::parseKeySet(['keys' => $this->discovery->keys($forceRefresh)]);

            // A leeway lokálisan állítjuk vissza: a JWT::$leeway globális statikus.
            $previousLeeway = JWT::$leeway;
            JWT::$leeway = (int) config('oidc.leeway');

            try {
                // A beágyazott claimek stdClass-ként jönnének; a szerep-claim pontozott
                // úton csak tömbként érhető el.
                return (array) json_decode(json_encode(JWT::decode($idToken, $keys)), associative: true);
            } finally {
                JWT::$leeway = $previousLeeway;
            }
        } catch (Throwable $e) {
            throw new AuthorizationException('Az ID-token aláírása nem ellenőrizhető.', previous: $e);
        }
    }

    /** @param array<string, mixed> $claims */
    private function assertIssuer(array $claims): void
    {
        if (($claims['iss'] ?? null) !== $this->discovery->issuer()) {
            throw new AuthorizationException('Az ID-token kibocsátója nem a ventSSO.');
        }
    }

    /**
     * `aud` + `azp` (OIDC Core 3.1.3.7): többelemű közönség esetén az `azp` kötelezően a
     * saját `client_id` — enélkül egy másik kliensnek kiállított token is átmenne.
     *
     * @param  array<string, mixed>  $claims
     */
    private function assertAudience(array $claims): void
    {
        $clientId = (string) config('oidc.client_id');
        $audience = (array) ($claims['aud'] ?? []);

        if (! in_array($clientId, $audience, true)) {
            throw new AuthorizationException('Az ID-token nem ennek a kliensnek szól.');
        }

        $azp = $claims['azp'] ?? null;

        if (count($audience) > 1 && $azp !== $clientId) {
            throw new AuthorizationException('Az ID-token elsődleges címzettje másik kliens.');
        }

        if ($azp !== null && $azp !== $clientId) {
            throw new AuthorizationException('Az ID-token `azp` claimje másik kliensre mutat.');
        }
    }

    /** @param array<string, mixed> $claims */
    private function assertSubject(array $claims): void
    {
        if (trim((string) ($claims['sub'] ?? '')) === '') {
            throw new AuthorizationException('Az ID-tokenben nincs `sub` claim.');
        }
    }

    /** @param array<string, mixed> $claims */
    private function assertNonce(array $claims, string $expectedNonce): void
    {
        if ($expectedNonce === '' || ! hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            throw new AuthorizationException('Az ID-token nonce-a nem egyezik (replay-kísérlet?).');
        }
    }
}
