<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\FakeSsoProvider;

uses(RefreshDatabase::class);

/*
 | M0-1 elfogadási kritérium #5, #6, #10 — valódi OIDC authorization-code + PKCE folyamat.
 | Az aláírás-ellenőrzés VALÓDI RS256 kulccsal fut (lásd FakeSsoProvider).
 */

beforeEach(function () {
    $this->sso = new FakeSsoProvider;

    config([
        'oidc.provider_url' => $this->sso->issuer,
        'oidc.client_id' => 'ventmail',
        'oidc.client_secret' => 'test-secret',
    ]);

    $this->sso->fakeHttp();
});

it('a belépés state, nonce és PKCE code_challenge kíséretében indul', function () {
    $response = $this->get('/auth/redirect');

    $response->assertRedirect();

    $url = $response->headers->get('Location');
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

    expect($url)->toStartWith($this->sso->issuer.'/oauth/authorize');
    expect($query['response_type'])->toBe('code');
    expect($query['client_id'])->toBe('ventmail');
    expect($query['code_challenge_method'])->toBe('S256');
    expect($query['code_challenge'])->not->toBeEmpty();
    expect($query['state'])->not->toBeEmpty();
    expect($query['nonce'])->not->toBeEmpty();

    // A verifier a szerveroldali sessionben marad — soha nem megy ki a böngészőn túl.
    expect(session('oidc.code_verifier'))->not->toBeEmpty();
    expect($query['code_challenge'])->not->toBe(session('oidc.code_verifier'));
});

it('a callback bejelentkezteti a ventSSO-alanyt', function () {
    $this->get('/auth/redirect');

    $this->sso->fakeHttp(['id_token' => $this->sso->idToken([
        'sub' => 'sso-subject-42',
        'email' => 'tamas@example.test',
        'name' => 'Vezér Tamás',
        'nonce' => session('oidc.nonce'),
        'resource_access' => ['ventmail' => ['roles' => ['analyst']]],
    ])]);

    $this->get('/auth/callback?code=auth-code&state='.session('oidc.state'))
        ->assertRedirect('/');

    expect(Auth::check())->toBeTrue();
    expect(User::where('sso_subject', 'sso-subject-42')->exists())->toBeTrue();
});

it('a felhasználót a sub azonosítja, nem az e-mail', function () {
    // Pre-registration account takeover elleni védelem: egy azonos e-mailű, korábbi sor
    // NEM vehető át egy másik ventSSO-alany által.
    $existing = User::factory()->create(['email' => 'tamas@example.test', 'sso_subject' => 'masik-alany']);

    $this->get('/auth/redirect');

    $this->sso->fakeHttp(['id_token' => $this->sso->idToken([
        'sub' => 'uj-alany',
        'email' => 'tamas@example.test',
        'nonce' => session('oidc.nonce'),
        'resource_access' => ['ventmail' => ['roles' => ['analyst']]],
    ])]);

    $this->get('/auth/callback?code=auth-code&state='.session('oidc.state'));

    expect(Auth::id())->not->toBe($existing->id);
    expect(User::where('sso_subject', 'uj-alany')->exists())->toBeTrue();
});

it('hamis state esetén elutasít', function () {
    $this->get('/auth/redirect');

    $this->get('/auth/callback?code=auth-code&state=hamisitott')
        ->assertForbidden();

    expect(Auth::check())->toBeFalse();
});

it('nem egyező nonce esetén elutasít', function () {
    $this->get('/auth/redirect');

    $this->sso->fakeHttp(['id_token' => $this->sso->idToken(['nonce' => 'masik-nonce'])]);

    $this->get('/auth/callback?code=auth-code&state='.session('oidc.state'))
        ->assertForbidden();

    expect(Auth::check())->toBeFalse();
});

it('lejárt vagy idegen kibocsátójú tokent elutasít', function () {
    $this->get('/auth/redirect');

    $this->sso->fakeHttp(['id_token' => $this->sso->idToken([
        'iss' => 'https://tamado.test',
        'nonce' => session('oidc.nonce'),
    ])]);

    $this->get('/auth/callback?code=auth-code&state='.session('oidc.state'))
        ->assertForbidden();

    expect(Auth::check())->toBeFalse();
});

it('a szerepeket a resource_access.ventmail.roles claimből képezi le', function () {
    $this->get('/auth/redirect');

    $this->sso->fakeHttp(['id_token' => $this->sso->idToken([
        'nonce' => session('oidc.nonce'),
        'resource_access' => ['ventmail' => ['roles' => ['developer', 'analyst', 'ismeretlen-szerep']]],
    ])]);

    $this->get('/auth/callback?code=auth-code&state='.session('oidc.state'));

    // Ismeretlen szerep NEM ad jogot (fehérlistás leképezés).
    // A session-kulcs szándékosan `_diagnostic`: jogosultsághoz TILOS használni.
    expect(session('oidc.roles_diagnostic'))->toEqualCanonicalizing([Role::Developer->value, Role::Analyst->value]);
});

it('a tenant-tagság a workspace_members-ből jön, nem a tokenből', function () {
    $workspace = Workspace::factory()->create();

    $this->get('/auth/redirect');

    $this->sso->fakeHttp(['id_token' => $this->sso->idToken([
        'sub' => 'sso-subject-77',
        'nonce' => session('oidc.nonce'),
        // A token workspace-tagságot ÁLLÍT — ezt figyelmen kívül kell hagyni.
        'workspaces' => [$workspace->uuid],
        'resource_access' => ['ventmail' => ['roles' => ['owner']]],
    ])]);

    $this->get('/auth/callback?code=auth-code&state='.session('oidc.state'));

    $user = User::where('sso_subject', 'sso-subject-77')->firstOrFail();

    expect($user->workspaces()->count())->toBe(0);
});
