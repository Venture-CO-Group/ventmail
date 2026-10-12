<?php

declare(strict_types=1);

namespace Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;

/**
 * Determinisztikus, VALÓDI RS256 aláírást használó ventSSO-dublőr.
 *
 * Nem stubolja az ellenőrző logikát: valódi kulcspárral írja alá az ID-tokent, és valódi
 * JWKS-t szolgál ki — így az aláírás-verifikáció, a kid-egyeztetés és a claim-ellenőrzés
 * ténylegesen tesztelve van. A hálózati réteg (discovery/token/jwks) az egyetlen, amit
 * fake-elünk; élesben ugyanez a kód a valódi ventSSO ellen fut.
 */
final class FakeSsoProvider
{
    public string $issuer = 'https://ventsso.test';

    private OpenSSLAsymmetricKey $privateKey;

    private array $jwk;

    public int $jwksRequests = 0;

    public function __construct(public string $kid = 'test-key-1')
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->privateKey = $key;

        $details = openssl_pkey_get_details($key);

        $this->jwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $this->kid,
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ];
    }

    /** Új kulcsra forgat — a régi `kid` ezután ismeretlen lesz a JWKS-ben. */
    public function rotate(string $newKid): void
    {
        $rotated = new self($newKid);

        $this->privateKey = $rotated->privateKey;
        $this->jwk = $rotated->jwk;
        $this->kid = $newKid;
    }

    public function idToken(array $claims = [], ?string $kid = null): string
    {
        $payload = array_merge([
            'iss' => $this->issuer,
            'aud' => config('oidc.client_id'),
            'sub' => 'sso-subject-1',
            'email' => 'user@example.test',
            'name' => 'Teszt Elek',
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => 'test-nonce',
        ], $claims);

        return JWT::encode($payload, $this->privateKey, 'RS256', $kid ?? $this->kid);
    }

    /**
     * A discovery, a token- és a JWKS-végpont fake-elése.
     *
     * A Http::fake() a korábbi stubokat NEM cseréli (az elsőként regisztrált minta nyer),
     * ezért új klienst teszünk a facade alá — különben a második hívás után is a régi
     * id_token jönne vissza, és a teszt hamis nonce-hibát látna.
     */
    public function fakeHttp(array $tokenResponse = []): void
    {
        Http::swap(new Factory);

        Http::fake([
            $this->issuer.'/.well-known/openid-configuration' => Http::response([
                'issuer' => $this->issuer,
                'authorization_endpoint' => $this->issuer.'/oauth/authorize',
                'token_endpoint' => $this->issuer.'/oauth/token',
                'jwks_uri' => $this->issuer.'/.well-known/jwks.json',
                'code_challenge_methods_supported' => ['S256'],
            ]),
            $this->issuer.'/.well-known/jwks.json' => function () {
                $this->jwksRequests++;

                return Http::response(['keys' => [$this->jwk]]);
            },
            $this->issuer.'/oauth/token' => Http::response(array_merge([
                'access_token' => 'access-token',
                'id_token' => $this->idToken(),
                'token_type' => 'Bearer',
                'expires_in' => 300,
            ], $tokenResponse)),
        ]);
    }

    /**
     * Passport-ALAKÚ access token, ahogy a ventSSO kiadja (AccessToken::toString): `kid`
     * NINCS a fejlécben, `iss` és `nonce` NINCS a claimekben; `aud`, `sub`, `jti`,
     * `scopes`, `iat`/`nbf`/`exp`, és a szerep-claimek (spec §5.7).
     */
    public function accessToken(array $claims = [], ?string $alg = 'RS256'): string
    {
        $payload = array_merge([
            'aud' => config('oidc.client_id'),
            'sub' => 'sso-subject-1',
            'jti' => bin2hex(random_bytes(8)),
            'scopes' => ['openid', 'profile', 'email'],
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 300,
        ], $claims);

        return JWT::encode($payload, $this->privateKey, $alg);
    }

    /** @return array<string, mixed> a publikus JWK */
    public function jwk(): array
    {
        return $this->jwk;
    }

    /** A publikus kulcs PEM formában (algoritmus-zavarásos negatív teszthez). */
    public function publicKeyPem(): string
    {
        return openssl_pkey_get_details($this->privateKey)['key'];
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
