<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeSsoProvider;

uses(RefreshDatabase::class);

/*
 | M0-1 elfogadási kritérium #5 második fele: ismeretlen `kid` esetén a kliens EGYSZER
 | üríti a JWKS-cache-t és újratölt. Enélkül a ventSSO kulcsrotációja néma login-hibát okoz.
 */

beforeEach(function () {
    $this->sso = new FakeSsoProvider;

    config(['oidc.provider_url' => $this->sso->issuer, 'oidc.client_id' => 'ventmail']);

    $this->sso->fakeHttp();
});

it('ismeretlen kid esetén újratölti a JWKS-t és sikeresen belép', function () {
    // Első belépés: bemelegíti a JWKS-cache-t.
    $this->get('/auth/redirect');
    $this->sso->fakeHttp(['id_token' => $this->sso->idToken(['nonce' => session('oidc.nonce'), 'resource_access' => ['ventmail' => ['roles' => ['analyst']]]])]);
    $this->get('/auth/callback?code=c&state='.session('oidc.state'));

    expect(Auth::check())->toBeTrue();
    $requestsAfterWarmup = $this->sso->jwksRequests;

    Auth::logout();
    session()->flush();

    // A ventSSO kulcsot forgat: a cache-elt JWKS már nem tartalmazza az új kid-et.
    $this->sso->rotate('test-key-2');

    $this->get('/auth/redirect');
    $this->sso->fakeHttp(['id_token' => $this->sso->idToken(['nonce' => session('oidc.nonce'), 'resource_access' => ['ventmail' => ['roles' => ['analyst']]]])]);
    $this->get('/auth/callback?code=c&state='.session('oidc.state'));

    expect(Auth::check())->toBeTrue('kulcsrotáció után nem sikerült a belépés');
    expect($this->sso->jwksRequests)->toBeGreaterThan($requestsAfterWarmup);
});

it('a JWKS-újratöltés nem ismételhető korlátlanul', function () {
    // Ismeretlen kid + rossz aláírás: a kliens EGYSZER tölt újra, aztán elutasít.
    $this->get('/auth/redirect');

    $foreign = new FakeSsoProvider('idegen-kid');
    $token = $foreign->idToken(['iss' => $this->sso->issuer, 'nonce' => session('oidc.nonce')]);

    $this->sso->fakeHttp(['id_token' => $token]);
    $before = $this->sso->jwksRequests;

    $this->get('/auth/callback?code=c&state='.session('oidc.state'))->assertRedirect('/')->assertSessionHas('error', __('ventmail.auth.retry'));

    // Hideg cache mellett egy normál + EGY rotációs újratöltés a plafon; a lényeg, hogy
    // a hibás aláírás ne indítson korlátlan JWKS-lekérést az IdP felé.
    expect($this->sso->jwksRequests - $before)->toBeLessThanOrEqual(2);
    expect(Auth::check())->toBeFalse();
});

it('a JWKS cache-elve van a normál úton', function () {
    Cache::flush();

    $this->get('/auth/redirect');
    $this->sso->fakeHttp(['id_token' => $this->sso->idToken(['nonce' => session('oidc.nonce'), 'resource_access' => ['ventmail' => ['roles' => ['analyst']]]])]);
    $this->get('/auth/callback?code=c&state='.session('oidc.state'));

    $first = $this->sso->jwksRequests;

    Auth::logout();
    session()->flush();

    $this->get('/auth/redirect');
    $this->sso->fakeHttp(['id_token' => $this->sso->idToken(['nonce' => session('oidc.nonce'), 'resource_access' => ['ventmail' => ['roles' => ['analyst']]]])]);
    $this->get('/auth/callback?code=c&state='.session('oidc.state'));

    expect($this->sso->jwksRequests)->toBe($first, 'a JWKS nincs cache-elve — minden login új lekérést indít');
});
