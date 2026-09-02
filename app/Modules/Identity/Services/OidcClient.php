<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Authorization-code folyamat PKCE-vel (S256).
 *
 * A `state` CSRF-védelem, a `nonce` replay-védelem, a PKCE `code_verifier` pedig a
 * kód-elfogás ellen véd. Mindhárom a szerveroldali sessionben él, sosem a kliensen.
 */
final class OidcClient
{
    public function __construct(
        private readonly OidcDiscovery $discovery,
        private readonly IdTokenVerifier $verifier,
    ) {}

    public function authorizationUrl(Request $request): string
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(96);

        $request->session()->put('oidc.state', $state);
        $request->session()->put('oidc.nonce', $nonce);
        $request->session()->put('oidc.code_verifier', $verifier);
        $request->session()->put('oidc.expires_at', now()->addSeconds((int) config('oidc.transient_ttl'))->timestamp);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('oidc.client_id'),
            'redirect_uri' => url(config('oidc.redirect_path')),
            'scope' => implode(' ', (array) config('oidc.scopes')),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->challengeFor($verifier),
            'code_challenge_method' => 'S256',
        ]);

        return $this->discovery->endpoint('authorization_endpoint').'?'.$query;
    }

    /**
     * A visszakapott kód beváltása és az ID-token ellenőrzése.
     *
     * @return array<string, mixed> a token claimjei
     *
     * @throws AuthorizationException
     */
    public function handleCallback(Request $request): array
    {
        // Az IdP hibát is visszairányítással jelez (pl. ?error=access_denied) — ilyenkor
        // nincs kód, és félrevezető „aláírás nem ellenőrizhető" hiba lenne belőle.
        if ($request->query('error') !== null) {
            throw new AuthorizationException(sprintf(
                'A ventSSO elutasította a bejelentkezést: %s',
                (string) $request->query('error')
            ));
        }

        $this->assertState($request);
        $this->assertNotExpired($request);

        $verifier = (string) $request->session()->pull('oidc.code_verifier', '');
        $nonce = (string) $request->session()->get('oidc.nonce', '');

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            throw new AuthorizationException('A ventSSO nem adott vissza authorization code-ot.');
        }

        $response = Http::asForm()->timeout(5)->connectTimeout(3)->post($this->discovery->endpoint('token_endpoint'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => url(config('oidc.redirect_path')),
            'client_id' => config('oidc.client_id'),
            'client_secret' => config('oidc.client_secret'),
            'code_verifier' => $verifier,
        ]);

        if (! $response->successful()) {
            throw new AuthorizationException('A ventSSO elutasította a kód beváltását.');
        }

        $idToken = (string) $response->json('id_token');

        if ($idToken === '') {
            throw new AuthorizationException('A ventSSO nem adott vissza ID-tokent.');
        }

        try {
            $claims = $this->verifier->verify($idToken, $nonce);
        } finally {
            // Egyszer használatos tranziensek — a HIBAÁGON is eldobjuk.
            $request->session()->forget(['oidc.state', 'oidc.nonce', 'oidc.expires_at']);
        }

        return $claims;
    }

    private function assertState(Request $request): void
    {
        $expected = (string) $request->session()->pull('oidc.state', '');
        $received = $request->query('state');
        $received = is_string($received) ? $received : '';

        if ($expected === '' || ! hash_equals($expected, $received)) {
            throw new AuthorizationException('Érvénytelen OIDC state (CSRF-kísérlet?).');
        }
    }

    private function assertNotExpired(Request $request): void
    {
        $expiresAt = (int) $request->session()->get('oidc.expires_at', 0);

        if ($expiresAt < now()->timestamp) {
            throw new AuthorizationException('A bejelentkezési folyamat lejárt, indítsd újra.');
        }
    }

    private function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
