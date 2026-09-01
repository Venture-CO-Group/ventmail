<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * ID-token ellenőrzés: aláírás (RS256/ES256, `kid` szerint), kibocsátó, közönség,
 * lejárat és `nonce`.
 *
 * Ismeretlen `kid` → EGYSZERI JWKS-újratöltés (kulcsrotáció), aztán végleges elutasítás.
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
        JWT::$leeway = (int) config('oidc.leeway');

        $claims = $this->decode($idToken, forceRefresh: false);

        $this->assertIssuer($claims);
        $this->assertAudience($claims);
        $this->assertNonce($claims, $expectedNonce);

        return $claims;
    }

    /** @return array<string, mixed> */
    private function decode(string $idToken, bool $forceRefresh): array
    {
        try {
            $keys = JWK::parseKeySet(['keys' => $this->discovery->keys($forceRefresh)]);

            // A JWT::decode beágyazott claimeket stdClass-ként ad vissza; a szerep-claim
            // (`resource_access.ventmail.roles`) pontozott úton csak tömbként érhető el.
            return (array) json_decode(json_encode(JWT::decode($idToken, $keys)), associative: true);
        } catch (Throwable $e) {
            if (! $forceRefresh) {
                // Kulcsrotáció: egyetlen újratöltés, nem több (DoS-védelem az IdP felé).
                return $this->decode($idToken, forceRefresh: true);
            }

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

    /** @param array<string, mixed> $claims */
    private function assertAudience(array $claims): void
    {
        $audience = (array) ($claims['aud'] ?? []);

        if (! in_array(config('oidc.client_id'), $audience, true)) {
            throw new AuthorizationException('Az ID-token nem ennek a kliensnek szól.');
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
