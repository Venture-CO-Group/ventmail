<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Auth;
use Tests\Support\FakeSsoProvider;

uses(RefreshDatabase::class);

/*
 | Az ÉLES ventSSO-kliens alakja (deploy-ovh 3.).
 |
 | A prod ventSSO-ban a kliens azonosítója UUID — az ID-token `aud`-ja is ez —, a szerepek
 | viszont a kliens NEVE alatt jönnek (`resource_access.ventmail.roles`). Ha a ventMail az
 | `aud`-ot a névvel, vagy a szerepeket a UUID-dal keresné, élesben senki nem tudna belépni,
 | miközben minden más teszt zöld. Ez a teszt azt az alakot méri, ami élesben lesz.
 */

const PROD_CLIENT = '9d3f6c1e-6b8a-4f0e-9a51-2a7c4b1d8e33';

beforeEach(function () {
    $this->sso = new FakeSsoProvider;

    config([
        'oidc.provider_url' => $this->sso->issuer,
        'oidc.client_id' => PROD_CLIENT,
        'oidc.client_secret' => 'test-secret',
        'oidc.roles_claim' => 'resource_access.ventmail.roles',
    ]);

    $this->sso->fakeHttp();
});

it('a kért scope-ok pontosan: openid profile email', function () {
    $defaults = configFileWith('oidc', ['OIDC_SCOPES' => null])['scopes'];
    expect($defaults)->toBe(['openid', 'profile', 'email']);
    config(['oidc.scopes' => $defaults]);

    $location = (string) $this->get('/auth/redirect')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe('openid profile email');
    expect($query['client_id'])->toBe(PROD_CLIENT);
});

it('UUID kliens-azonosítóval és név alatti szerepekkel belép', function () {
    $this->get('/auth/redirect');

    $this->sso->fakeHttp(['id_token' => $this->sso->idToken([
        'aud' => PROD_CLIENT,
        'nonce' => session('oidc.nonce'),
        'resource_access' => ['ventmail' => ['roles' => ['owner']]],
    ])]);

    $this->get('/auth/callback?code=auth-code&state='.session('oidc.state'))->assertRedirect();

    expect(Auth::check())->toBeTrue();
    expect(session('oidc.roles_diagnostic'))->toBe([Role::Owner->value]);
});

it('más kliensnek kiadott (rossz audience) tokent elutasít', function () {
    $this->get('/auth/redirect');

    $this->sso->fakeHttp(['id_token' => $this->sso->idToken([
        'aud' => 'ventmail', // a kliens NEVE, nem az azonosítója
        'nonce' => session('oidc.nonce'),
        'resource_access' => ['ventmail' => ['roles' => ['owner']]],
    ])]);

    $this->get('/auth/callback?code=auth-code&state='.session('oidc.state'))->assertForbidden();

    expect(Auth::check())->toBeFalse();
});

it('lejárt tokent elutasít', function () {
    $this->get('/auth/redirect');

    $this->sso->fakeHttp(['id_token' => $this->sso->idToken([
        'aud' => PROD_CLIENT,
        'nonce' => session('oidc.nonce'),
        'iat' => time() - 3600,
        'exp' => time() - 1800,
        'resource_access' => ['ventmail' => ['roles' => ['owner']]],
    ])]);

    $this->get('/auth/callback?code=auth-code&state='.session('oidc.state'))->assertForbidden();

    expect(Auth::check())->toBeFalse();
});

it('a redirect URI az APP_URL-ből jön, https-sel, az edge mögött is', function () {
    config(['app.url' => 'https://app.ventmail.app', 'proxy.trusted_proxies' => ['172.30.0.2/32']]);
    URL::forceRootUrl('https://app.ventmail.app');

    $location = (string) $this->withServerVariables(['REMOTE_ADDR' => '172.30.0.2'])
        ->get('http://app.ventmail.app/auth/redirect', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'app.ventmail.app'])
        ->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['redirect_uri'])->toBe('https://app.ventmail.app/auth/callback');
});

it('a kijelentkezés a ventSSO end_session végpontjára visz, a kliens azonosítójával', function () {
    Http::swap(new Factory);
    Http::fake([$this->sso->issuer.'/.well-known/openid-configuration' => Http::response([
        'issuer' => $this->sso->issuer,
        'authorization_endpoint' => $this->sso->issuer.'/oauth/authorize',
        'token_endpoint' => $this->sso->issuer.'/oauth/token',
        'jwks_uri' => $this->sso->issuer.'/.well-known/jwks.json',
        'end_session_endpoint' => $this->sso->issuer.'/oauth/logout',
    ])]);

    $location = (string) $this->post('/auth/logout')->headers->get('Location');

    expect($location)->toStartWith($this->sso->issuer.'/oauth/logout?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query['client_id'])->toBe(PROD_CLIENT);
    expect($query)->toHaveKey('post_logout_redirect_uri');
});

it('az app-héjból (Inertia) indított kijelentkezés is eljut a ventSSO-ig', function () {
    Http::swap(new Factory);
    Http::fake([$this->sso->issuer.'/.well-known/openid-configuration' => Http::response([
        'issuer' => $this->sso->issuer,
        'authorization_endpoint' => $this->sso->issuer.'/oauth/authorize',
        'token_endpoint' => $this->sso->issuer.'/oauth/token',
        'jwks_uri' => $this->sso->issuer.'/.well-known/jwks.json',
        'end_session_endpoint' => $this->sso->issuer.'/oauth/logout',
    ])]);

    // Egy Inertia XHR nem követ külső átirányítást: 409 + X-Inertia-Location kell.
    $response = $this->post('/auth/logout', [], ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest']);

    $response->assertStatus(409);
    expect((string) $response->headers->get('X-Inertia-Location'))->toStartWith($this->sso->issuer.'/oauth/logout?');
});
