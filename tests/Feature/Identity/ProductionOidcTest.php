<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\Role;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
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

    $this->get('/auth/callback?code=auth-code&state='.session('oidc.state'))->assertRedirect('/');

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

    $this->get('/auth/callback?code=auth-code&state='.session('oidc.state'))->assertRedirect('/');

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

/*
 | A ventSSO a szerepeket az ACCESS TOKENBE teszi (Passport JWT, spec §5.7), az ID-tokenbe
 | nem — élesben mérve: a szerep nélkülinek látott alany 403-at kapott a callbacken.
 | Az access token Passport-alakú: `kid` és `iss` nélkül (FakeSsoProvider::accessToken).
 */
function loginWithTokens(object $t, array $idClaims, string $accessToken): TestResponse
{
    $t->get('/auth/redirect');
    $t->sso->fakeHttp([
        'id_token' => $t->sso->idToken(array_merge(['aud' => PROD_CLIENT, 'nonce' => session('oidc.nonce')], $idClaims)),
        'access_token' => $accessToken,
    ]);

    return $t->get('/auth/callback?code=auth-code&state='.session('oidc.state'));
}

it('a szerepeket a kid nélküli, ellenőrzött access tokenből veszi át', function () {
    loginWithTokens($this, [], $this->sso->accessToken([
        'aud' => PROD_CLIENT,
        'resource_access' => ['ventmail' => ['roles' => ['owner']]],
        'realm_access' => ['roles' => ['staff']],
    ]))->assertRedirect();

    expect(Auth::check())->toBeTrue();
    expect(session('oidc.roles_diagnostic'))->toBe([Role::Owner->value]);
});

it('az access tokenből identitás-claim SOHA nem jön át', function () {
    loginWithTokens($this, ['email' => 'valodi@example.test', 'name' => 'Valódi'], $this->sso->accessToken([
        'aud' => PROD_CLIENT,
        'email' => 'tamado@example.test', 'name' => 'Támadó', 'act' => ['sub' => 'x'],
        'resource_access' => ['ventmail' => ['roles' => ['owner']]],
    ]))->assertRedirect();

    expect(Auth::user()->email)->toBe('valodi@example.test')
        ->and(Auth::user()->name)->toBe('Valódi');
});

it('ha az ID-token hoz szerepet, az access token nem írja felül', function () {
    loginWithTokens($this, ['resource_access' => ['ventmail' => ['roles' => ['analyst']]]], $this->sso->accessToken([
        'aud' => PROD_CLIENT, 'resource_access' => ['ventmail' => ['roles' => ['owner']]],
    ]))->assertRedirect();

    expect(session('oidc.roles_diagnostic'))->toBe([Role::Analyst->value]);
});

it('a platform-admin jog az access tokenből is megadódik, auditáltan', function () {
    loginWithTokens($this, [], $this->sso->accessToken([
        'aud' => PROD_CLIENT,
        'resource_access' => ['ventmail' => ['roles' => ['owner']], 'ventmail-platform' => ['roles' => ['platform_admin']]],
    ]))->assertRedirect();

    expect(Auth::user()->isPlatformAdmin())->toBeTrue();
    expect(AuditLog::query()->withoutGlobalScopes()->where('action', 'platform.admin_granted')->exists())->toBeTrue();
});

it('hamis, idegen, lejárt vagy sérült access tokenből NEM vesz át szerepet', function (string $case) {
    $roles = ['resource_access' => ['ventmail' => ['roles' => ['owner']]]];

    $token = match ($case) {
        'idegen kulcs' => (new FakeSsoProvider('mas-kulcs'))->accessToken(['aud' => PROD_CLIENT, ...$roles]),
        'más alany' => $this->sso->accessToken(['aud' => PROD_CLIENT, 'sub' => 'mas-alany', ...$roles]),
        'üres alany' => $this->sso->accessToken(['aud' => PROD_CLIENT, 'sub' => '', ...$roles]),
        'más kliens' => $this->sso->accessToken(['aud' => 'mas-kliens', ...$roles]),
        'lejárt' => $this->sso->accessToken(['aud' => PROD_CLIENT, 'iat' => time() - 7200, 'nbf' => time() - 7200, 'exp' => time() - 3600, ...$roles]),
        'alg none' => rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=').'.'.rtrim(strtr(base64_encode(json_encode(['aud' => PROD_CLIENT, 'sub' => 'sso-subject-1', 'exp' => time() + 300, ...$roles])), '+/', '-_'), '=').'.',
        'HS256 a publikus kulccsal' => JWT::encode(['aud' => PROD_CLIENT, 'sub' => 'sso-subject-1', 'exp' => time() + 300, ...$roles], $this->sso->publicKeyPem(), 'HS256'),
        'nem JWT' => 'opaque-access-token',
    };

    // A hamis access tokenből NEM veszünk át szerepet → szerep nélküli alany → megtagadva (403).
    loginWithTokens($this, [], $token)->assertForbidden();

    expect(Auth::check())->toBeFalse();
})->with(['idegen kulcs', 'más alany', 'üres alany', 'más kliens', 'lejárt', 'alg none', 'HS256 a publikus kulccsal', 'nem JWT']);

it('ventSSO-impersonációval (act) érkező belépést elutasít — a ventMail saját, csak olvasó támogatói nézete a helyes út', function () {
    loginWithTokens($this, ['act' => ['sub' => 'admin-alany']], $this->sso->accessToken([
        'aud' => PROD_CLIENT, 'act' => ['sub' => 'admin-alany'], 'resource_access' => ['ventmail' => ['roles' => ['owner']]],
    ]))->assertRedirect('/');

    expect(Auth::check())->toBeFalse();
});
