<?php

declare(strict_types=1);

use App\Modules\VentSec\Services\ServiceTokenProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 | FO2 — m2m token a ventSSO-tól (client_credentials, ventmail → ventsec). A ventMail itt
 | CSAK fogyasztó: a tokent a ventSSO adja ki, a kliens-titok env-ből jön. A token
 | cache-elt (titkosítva), élettartama a lejárat előtt 60 másodperccel ér véget.
 */

beforeEach(function () {
    Cache::flush();
    config([
        'ventsec.token_url' => 'https://sso.test/oauth/token',
        'ventsec.client_id' => 'ventmail-prod',
        'ventsec.client_secret' => 's3cret-value',
        'ventsec.resource' => 'https://sec.ventureco.app',
        'ventsec.scope' => 'ventsec.scan ventsec.ingest',
    ]);
});

/** Egy (nem ellenőrzött aláírású) JWT, csak az `exp` miatt — a ventSec ellenőrzi. */
function fakeJwt(int $exp): string
{
    $part = fn (array $d) => rtrim(strtr(base64_encode((string) json_encode($d)), '+/', '-_'), '=');

    return $part(['alg' => 'RS256']).'.'.$part(['exp' => $exp, 'sub' => 'ventmail-prod']).'.sig';
}

it('client_credentials kérést küld basic-hitelesítéssel, resource-szal és scope-pal', function () {
    Http::fake(['sso.test/*' => Http::response(['access_token' => fakeJwt(time() + 600), 'token_type' => 'Bearer', 'expires_in' => 600])]);

    $token = app(ServiceTokenProvider::class)->token();

    expect($token)->toStartWith('ey');

    Http::assertSent(function (Request $request) {
        parse_str($request->body(), $form);

        return $request->url() === 'https://sso.test/oauth/token'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('ventmail-prod:s3cret-value'))
            && ($form['grant_type'] ?? null) === 'client_credentials'
            && ($form['scope'] ?? null) === 'ventsec.scan ventsec.ingest'
            && ($form['resource'] ?? null) === 'https://sec.ventureco.app'
            && ! str_contains($request->body(), 's3cret-value');
    });
});

it('a tokent cache-eli, és a cache nem tartalmazza nyersen', function () {
    Http::fake(['sso.test/*' => Http::response(['access_token' => fakeJwt(time() + 600), 'expires_in' => 600])]);

    $provider = app(ServiceTokenProvider::class);
    $first = $provider->token();
    $second = $provider->token();

    expect($second)->toBe($first);
    Http::assertSentCount(1);
});

it('a cache-élettartam a lejárat előtt 60 másodperccel ér véget (a JWT exp-je nyer, ha korábbi)', function () {
    $sent = 0;
    Http::fake(function () use (&$sent) {
        $sent++;

        // expires_in 3600, de a JWT exp 200 s múlva: a rövidebb számít (200-60 = 140 s).
        return Http::response(['access_token' => fakeJwt(time() + 200), 'expires_in' => 3600]);
    });

    $provider = app(ServiceTokenProvider::class);
    $provider->token();

    $this->travel(120)->seconds();
    $provider->token();
    expect($sent)->toBe(1);

    $this->travel(30)->seconds(); // 150 s > 140 s
    $provider->token();
    expect($sent)->toBe(2);
});

it('a túl rövid élettartamú tokent nem cache-eli, de visszaadja', function () {
    $sent = 0;
    Http::fake(function () use (&$sent) {
        $sent++;

        return Http::response(['access_token' => fakeJwt(time() + 30), 'expires_in' => 30]);
    });

    $provider = app(ServiceTokenProvider::class);

    expect($provider->token())->not->toBeNull();
    $provider->token();
    expect($sent)->toBe(2);
});

it('hiba esetén null — nem dob, és a titok nem kerül a logba', function () {
    Log::spy();

    foreach ([
        Http::response(['error' => 'invalid_client'], 401),
        Http::response('nem json', 200),
        Http::response(['token_type' => 'Bearer'], 200),
        Http::response(['access_token' => ''], 200),
        Http::response([], 500),
    ] as $response) {
        Cache::flush();
        Http::swap(new Factory);
        Http::fake(['sso.test/*' => $response]);

        expect(app(ServiceTokenProvider::class)->token())->toBeNull();
    }

    Http::swap(new Factory);
    Http::fake(['sso.test/*' => fn () => throw new ConnectionException('timeout')]);
    Cache::flush();
    expect(app(ServiceTokenProvider::class)->token())->toBeNull();

    Log::shouldNotHaveReceived('error', fn ($message, $context = []) => str_contains(json_encode([$message, $context]), 's3cret-value'));
});

it('hiányzó konfigurációval nem is próbálkozik', function () {
    Http::fake();
    config(['ventsec.client_secret' => '']);

    expect(app(ServiceTokenProvider::class)->token())->toBeNull();
    Http::assertNothingSent();
});

it('nem titkosított token-végpontot nem hív (https-kényszer)', function () {
    Http::fake();
    config(['ventsec.token_url' => 'http://sso.test/oauth/token']);

    expect(app(ServiceTokenProvider::class)->token())->toBeNull();
    Http::assertNothingSent();
});
