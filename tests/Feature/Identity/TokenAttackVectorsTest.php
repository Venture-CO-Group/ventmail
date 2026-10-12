<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeSsoProvider;

uses(RefreshDatabase::class);

/*
 | Negatív vektorok a token-verifikációra. A security-review kifejezetten ezeket kérte:
 | a discovery-hamisítást egy ilyen teszt fogta volna meg.
 */

beforeEach(function () {
    $this->sso = new FakeSsoProvider;

    config([
        'oidc.provider_url' => $this->sso->issuer,
        'oidc.client_id' => 'ventmail',
        'oidc.client_secret' => 'test-secret',
    ]);
});

function attemptLogin(string $idToken, array $discovery = []): TestResponse
{
    /** @var FakeSsoProvider $sso */
    $sso = test()->sso;

    $sso->fakeHttp();
    test()->get('/auth/redirect');

    $sso->fakeHttp(['id_token' => $idToken], $discovery);

    return test()->get('/auth/callback?code=c&state='.session('oidc.state'));
}

it('idegen issuert hirdető discovery esetén nincs belépés', function () {
    // A támadó a discovery-választ befolyásolja: saját issuert ÉS saját JWKS-t hirdet,
    // majd a saját kulcsával ír alá. Ha az issuer nincs a konfigurációhoz kötve,
    // az `iss`-ellenőrzés önhivatkozóvá válik, és a token átmegy.
    $attacker = new FakeSsoProvider('tamado-kid');

    $token = $attacker->idToken([
        'iss' => 'https://tamado.example',
        'sub' => 'evil-sub',
        'nonce' => 'placeholder',
    ]);

    $this->sso->fakeHttp();
    $this->get('/auth/redirect');

    $token = $attacker->idToken([
        'iss' => 'https://tamado.example',
        'sub' => 'evil-sub',
        'nonce' => session('oidc.nonce'),
    ]);

    $this->sso->fakeHttp(['id_token' => $token], [
        'issuer' => 'https://tamado.example',
        'jwks_uri' => 'https://tamado.example/.well-known/jwks.json',
    ]);

    Http::fake([
        'https://tamado.example/.well-known/jwks.json' => Http::response(['keys' => [$attacker->jwk()]]),
    ]);

    $this->get('/auth/callback?code=c&state='.session('oidc.state'))->assertRedirect('/');

    expect(Auth::check())->toBeFalse();
});

it('nem https végpontot hirdető discovery esetén nincs belépés', function () {
    config(['oidc.provider_url' => 'https://ventsso.test']);

    $this->sso->issuer = 'https://ventsso.test';

    $response = attemptLogin($this->sso->idToken(), [
        'jwks_uri' => 'http://ventsso.test/.well-known/jwks.json',
    ]);

    $response->assertRedirect('/');
    expect(Auth::check())->toBeFalse();
});

it('alg=none tokent elutasít', function () {
    $payload = [
        'iss' => $this->sso->issuer,
        'aud' => 'ventmail',
        'sub' => 'evil',
        'exp' => time() + 300,
        'nonce' => 'x',
    ];

    $unsigned = JWT::urlsafeB64Encode(json_encode(['alg' => 'none', 'typ' => 'JWT']))
        .'.'.JWT::urlsafeB64Encode(json_encode($payload)).'.';

    attemptLogin($unsigned)->assertRedirect('/');
    expect(Auth::check())->toBeFalse();
});

it('HS256-konfúziót a JWKS RSA kulcsával elutasít', function () {
    // Klasszikus algoritmus-zavarás: a publikus kulcsot HMAC-titokként használva.
    $payload = [
        'iss' => $this->sso->issuer,
        'aud' => 'ventmail',
        'sub' => 'evil',
        'exp' => time() + 300,
        'nonce' => 'x',
    ];

    $forged = JWT::encode($payload, $this->sso->publicKeyPem(), 'HS256', $this->sso->kid);

    attemptLogin($forged)->assertRedirect('/');
    expect(Auth::check())->toBeFalse();
});

it('másik kliensnek szánt tokent (azp) elutasít', function () {
    // OIDC Core 3.1.3.7: többelemű `aud` esetén az `azp` kötelezően a saját client_id.
    $this->sso->fakeHttp();
    $this->get('/auth/redirect');

    $token = $this->sso->idToken([
        'aud' => ['ventmail', 'masik-kliens'],
        'azp' => 'masik-kliens',
        'nonce' => session('oidc.nonce'),
    ]);

    $this->sso->fakeHttp(['id_token' => $token]);

    $this->get('/auth/callback?code=c&state='.session('oidc.state'))->assertRedirect('/');
    expect(Auth::check())->toBeFalse();
});

it('sub nélküli tokent elutasít', function () {
    $this->sso->fakeHttp();
    $this->get('/auth/redirect');

    $token = $this->sso->idToken(['sub' => '', 'nonce' => session('oidc.nonce')]);
    $this->sso->fakeHttp(['id_token' => $token]);

    $this->get('/auth/callback?code=c&state='.session('oidc.state'))->assertRedirect('/');
});

it('lejárt token nem indít JWKS-újratöltést', function () {
    // A kivétel-alapú újratöltés cache-stampede-et okozna az IdP ellen óracsúszás esetén.
    $this->sso->fakeHttp();
    $this->get('/auth/redirect');
    $this->sso->fakeHttp(['id_token' => $this->sso->idToken([
        'nonce' => session('oidc.nonce'),
        'resource_access' => ['ventmail' => ['roles' => ['analyst']]],
    ])]);
    $this->get('/auth/callback?code=c&state='.session('oidc.state'));

    Auth::logout();
    session()->flush();

    $this->sso->fakeHttp();
    $this->get('/auth/redirect');
    $before = $this->sso->jwksRequests;

    $this->sso->fakeHttp(['id_token' => $this->sso->idToken([
        'exp' => time() - 3600,
        'nonce' => session('oidc.nonce'),
    ])]);

    $this->get('/auth/callback?code=c&state='.session('oidc.state'))->assertRedirect('/');

    expect($this->sso->jwksRequests)->toBe($before, 'a lejárt token JWKS-újratöltést indított');
});
