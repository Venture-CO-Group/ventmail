<?php

declare(strict_types=1);

use App\Support\ClientAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 | ÉLES ÚTVONAL: kliens → edge Caddy (TLS) → ventMail web (Caddy) → php-fpm (deploy-ovh 3.).
 |
 | A PHP sima HTTP-t lát az edge-hálózatról, tehát az URL-generálás és a kliens-IP CSAK akkor
 | helyes, ha az `X-Forwarded-*` fejlécet az edge-től elfogadjuk — és KIZÁRÓLAG tőle. Ugyanaz
 | a minta és ugyanazok a tesztek, mint a ventSSO-nál.
 */

const EDGE = '172.30.0.2';

beforeEach(function () {
    Route::get('/__proxy-probe', fn (Request $request) => response()->json([
        'url' => url('/valahol'),
        'secure' => $request->isSecure(),
        'client' => ClientAddress::of($request),
    ]));

    config(['proxy.trusted_proxies' => [EDGE.'/32']]);
});

// A trusted hostok a Request osztály statikus állapotában élnek — a következő tesztbe nem szivároghatnak.
afterEach(function () {
    Request::setTrustedHosts([]);
    Request::setTrustedProxies([], -1);
});

$forwarded = [
    'X-Forwarded-Proto' => 'https',
    'X-Forwarded-Host' => 'app.ventmail.app',
    'X-Forwarded-For' => '203.0.113.7',
];

it('az edge mögött https URL-t generál, és a valódi kliens-IP-t látja', function () use ($forwarded) {
    $response = $this->withServerVariables(['REMOTE_ADDR' => EDGE])
        ->getJson('http://app.ventmail.app/__proxy-probe', $forwarded);

    expect($response->json('url'))->toStartWith('https://')
        ->and($response->json('secure'))->toBeTrue()
        ->and($response->json('client'))->toBe('203.0.113.7');
});

it('bárki mástól a továbbított fejléceket figyelmen kívül hagyja', function () use ($forwarded) {
    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->getJson('http://app.ventmail.app/__proxy-probe', $forwarded);

    expect($response->json('url'))->toStartWith('http://')
        ->and($response->json('secure'))->toBeFalse()
        ->and($response->json('client'))->toBe('198.51.100.9');
});

it('beállított proxy nélkül senkinek nem hisz', function () use ($forwarded) {
    config(['proxy.trusted_proxies' => []]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => EDGE])
        ->getJson('http://app.ventmail.app/__proxy-probe', $forwarded);

    expect($response->json('secure'))->toBeFalse()
        ->and($response->json('client'))->toBe(EDGE);
});

it('a továbbított prefix és port az edge-től sem irányíthatja az URL-eket', function () use ($forwarded) {
    $response = $this->withServerVariables(['REMOTE_ADDR' => EDGE])
        ->getJson('http://app.ventmail.app/__proxy-probe', $forwarded + [
            'X-Forwarded-Prefix' => '@evil.example',
            'X-Forwarded-Port' => '8443',
        ]);

    expect($response->json('url'))->toBe('https://app.ventmail.app/valahol');
});

it('a TRUSTED_PROXIES listát olvassa, és alapból SENKINEK nem hisz (nincs `*`)', function () {
    expect(configFileWith('proxy', ['TRUSTED_PROXIES' => '172.30.0.2/32, 10.0.0.0/8'])['trusted_proxies'])
        ->toBe(['172.30.0.2/32', '10.0.0.0/8']);
    expect(configFileWith('proxy', ['TRUSTED_PROXIES' => null])['trusted_proxies'])->toBe([]);
    expect(configFileWith('proxy', ['TRUSTED_PROXIES' => ''])['trusted_proxies'])->toBe([]);
});

it('a megbízható hostok alapból az APP_URL hostja, listával bővíthető', function () {
    expect(configFileWith('proxy', ['TRUSTED_HOSTS' => null, 'APP_URL' => 'https://app.ventmail.app'])['trusted_hosts'])
        ->toBe(['app.ventmail.app']);
    expect(configFileWith('proxy', ['TRUSTED_HOSTS' => 'app.ventmail.app,api.ventmail.app'])['trusted_hosts'])
        ->toBe(['app.ventmail.app', 'api.ventmail.app']);
});

it('élesben az ismeretlen hostra érkező kérést elutasítja, az app és az api hostot kiszolgálja', function () {
    config(['proxy.trusted_hosts' => ['app.ventmail.app', 'api.ventmail.app']]);
    app()->detectEnvironment(fn () => 'production');

    $this->get('http://evil.example/up')->assertStatus(400);
    $this->get('http://app.ventmail.app/up')->assertOk();
    $this->get('http://api.ventmail.app/up')->assertOk();
});

it('élesben üres host-listával fail-closed', function () {
    config(['proxy.trusted_hosts' => []]);
    app()->detectEnvironment(fn () => 'production');

    $this->get('http://akarmi.example/up')->assertStatus(500);
});

it('a session-süti alapból __Host- előtagú, és az előtag kikényszeríti az attribútumokat', function () {
    $config = configFileWith('session', [
        'SESSION_COOKIE' => null,
        'SESSION_DOMAIN' => '.ventmail.app',
        'SESSION_SECURE_COOKIE' => 'false',
        'SESSION_PATH' => '/app',
        'SESSION_HTTP_ONLY' => 'false',
    ]);

    expect($config['cookie'])->toBe('__Host-ventmail_session')
        ->and($config['domain'])->toBeNull()
        ->and($config['secure'])->toBeTrue()
        ->and($config['http_only'])->toBeTrue()
        ->and($config['path'])->toBe('/');
});

it('a kiküldött session-süti Secure, HttpOnly, SameSite=Lax, Path=/ és Domain nélküli', function () {
    config([
        'session.cookie' => '__Host-ventmail_session',
        'session.secure' => true,
        'session.domain' => null,
        'session.path' => '/',
        'session.same_site' => 'lax',
    ]);

    $response = $this->get('/');

    $header = collect($response->headers->all('set-cookie'))
        ->first(fn (string $line) => str_starts_with($line, '__Host-ventmail_session='));

    expect($header)->not->toBeNull()
        ->and($header)->toMatch('/;\s*secure/i')
        ->and($header)->toMatch('/;\s*httponly/i')
        ->and($header)->toMatch('/;\s*samesite=lax/i')
        ->and($header)->toMatch('/;\s*path=\/(;|$)/i')
        ->and($header)->not->toMatch('/;\s*domain=/i');
});
