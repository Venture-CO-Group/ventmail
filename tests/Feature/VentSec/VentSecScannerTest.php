<?php

declare(strict_types=1);

use App\Modules\Inbound\Services\VentSecScanner;
use App\Modules\VentSec\Support\Jcs;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 | FO2 — VentSecScanner: a bejövő csatolmányok a ventSec Scan API-n mennek át, fail-closed.
 | A ventSec ítélet-aláírását a ventSec FÜGGETLEN vektorának kulcsával állítjuk elő/ellenőrizzük
 | (seed a vektorból); a clean/infected csak érvényes, EZRE a tartalomra szóló aláírással élhet.
 */

const SCAN_FILE = "%PDF-1.4 minta tartalom\n";

beforeEach(function () {
    Cache::flush();
    config([
        'inbound.scanner' => 'ventsec',
        'ventsec.token_url' => 'https://sso.test/oauth/token',
        'ventsec.client_id' => 'ventmail-prod',
        'ventsec.client_secret' => 'titok',
        'ventsec.scan_url' => 'https://sec.test',
        'ventsec.keys_url' => 'https://sec.test/.well-known/ventsec-verdict-keys.json',
    ]);

    $doc = json_decode((string) file_get_contents(base_path('tests/Fixtures/ventsec/scan-verdict.json')), true);
    $pair = sodium_crypto_sign_seed_keypair((string) hex2bin($doc['seed_hex']));
    test()->secret = sodium_crypto_sign_secretkey($pair);
    test()->public = sodium_crypto_sign_publickey($pair);
    test()->keyId = $doc['key_id'];
});

function jwkOf(string $public, ?string $kid = null): array
{
    return ['kid' => $kid ?? substr(hash('sha256', $public), 0, 16), 'kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'use' => 'sig', 'x' => b64u($public)];
}

/** Aláírt ítélet EZRE a tartalomra (a mezők felülírhatók a hamisítás-tesztekhez). */
function signedVerdict(string $contents, array $override = [], ?string $secret = null): array
{
    $payload = array_merge([
        'scan_id' => '01J9Z6Q7Y3X5R0K8ZC4M2T1H6B',
        'key_id' => test()->keyId,
        'ref' => 'ventmail:'.substr(hash('sha256', $contents), 0, 32),
        'requester' => 'ventmail',
        'sha256' => hash('sha256', $contents),
        'size' => strlen($contents),
        'detected_type' => 'application/pdf',
        'verdict' => 'clean',
        'binary_verdict' => 'clean',
        'recommended_action' => 'allow',
        'flagged' => false,
        'profile' => 'mail-inbound',
        'steps' => [['step' => 'filetype', 'result' => 'clean'], ['step' => 'clamav', 'result' => 'clean']],
        'engine_versions' => ['clamav' => '1.4.3', 'daily' => '28151', 'yara' => 'a3adad7444ae'],
        'stale_signatures' => false,
        'scanned_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
    ], $override);

    $object = json_decode((string) json_encode($payload), false);
    $signature = sodium_crypto_sign_detached("ventsec.scan-verdict.v1\n".Jcs::canonicalize($object), $secret ?? test()->secret);

    return [...$payload, 'signature' => b64u($signature)];
}

function fakeVentSec(array|Closure $scan, ?array $keys = null, int $scanStatus = 200): void
{
    Http::swap(new Factory);
    Http::fake([
        'sso.test/*' => Http::response(['access_token' => 'tok.en.sig', 'expires_in' => 600]),
        'sec.test/.well-known/*' => Http::response($keys ?? ['keys' => [jwkOf(test()->public)]]),
        'sec.test/scan/v1/files' => $scan instanceof Closure ? $scan : Http::response($scan, $scanStatus),
    ]);
}

function scanner(): VentSecScanner
{
    return app(VentSecScanner::class);
}

it('tiszta ítéletre ScanResult::clean — a kérés helyes végpontra, fejlécekkel, nyers törzzsel megy', function () {
    fakeVentSec(signedVerdict(SCAN_FILE));

    $result = scanner()->scan('szamla.pdf', SCAN_FILE);

    expect($result->status)->toBe('clean');

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/scan/v1/files')) {
            return false;
        }

        return $request->url() === 'https://sec.test/scan/v1/files'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer tok.en.sig')
            && $request->hasHeader('X-VentSec-Profile', 'mail-inbound')
            && $request->hasHeader('X-VentSec-Declared-Ext', 'pdf')
            && $request->hasHeader('X-VentSec-Ref', 'ventmail:'.substr(hash('sha256', SCAN_FILE), 0, 32))
            && $request->hasHeader('Idempotency-Key')
            && str_starts_with($request->header('Content-Type')[0], 'application/octet-stream')
            && $request->body() === SCAN_FILE;
    });
});

it('a fájlnév SEHOL nem jelenik meg a kimenő kérésben — csak a kiterjesztés', function () {
    fakeVentSec(signedVerdict(SCAN_FILE));
    Log::spy();

    scanner()->scan('Számla_Kovács_Béla.pdf', SCAN_FILE);

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/scan/v1/files')) {
            return true;
        }

        $dump = $request->url().json_encode($request->headers()).$request->body();

        return ! str_contains($dump, 'Kovács') && ! str_contains($dump, 'Kov%C3%A1cs') && ! str_contains($dump, 'Béla')
            && ! str_contains($dump, 'Számla') && $request->hasHeader('X-VentSec-Declared-Ext', 'pdf');
    });

    Log::shouldNotHaveReceived('warning', fn ($m, $c = []) => str_contains(json_encode([$m, $c]), 'Kovács'));
});

it('érvénytelen kiterjesztésnél nem küld Declared-Ext fejlécet', function () {
    fakeVentSec(signedVerdict(SCAN_FILE));

    foreach (['nincs-kiterjesztes', 'a.túlságosanhosszúkiterjesztés', 'x.p d f', ''] as $name) {
        scanner()->scan($name, SCAN_FILE);
    }

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/scan/v1/files'));
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/scan/v1/files') && $r->hasHeader('X-VentSec-Declared-Ext'));
});

it('fertőzött ítéletre ScanResult::infected a szignatúra nevével', function () {
    $contents = 'X5O!P%@AP-minta';
    fakeVentSec(signedVerdict($contents, [
        'verdict' => 'infected', 'binary_verdict' => 'infected', 'recommended_action' => 'reject',
        'steps' => [['step' => 'filetype', 'result' => 'clean'], ['step' => 'clamav', 'result' => 'infected', 'signature' => 'Eicar-Test-Signature']],
    ]));

    $result = scanner()->scan('eicar.com', $contents);

    expect($result->status)->toBe('infected')->and($result->detail)->toBe('Eicar-Test-Signature');
});

it('a fertőzött ítélet szignatúra-név nélkül is infected marad', function () {
    fakeVentSec(signedVerdict('x', ['binary_verdict' => 'infected', 'verdict' => 'infected', 'steps' => []]));

    expect(scanner()->scan('a.bin', 'x')->status)->toBe('infected');
});

it('minden más: fail-closed unavailable', function (string $case) {
    $contents = SCAN_FILE;
    $ok = signedVerdict($contents);

    match ($case) {
        '5xx' => fakeVentSec(['error' => 'scanner_unavailable'], scanStatus: 503),
        '429' => fakeVentSec(['error' => 'rate_limited'], scanStatus: 429),
        '413' => fakeVentSec(['error' => 'payload_too_large'], scanStatus: 413),
        'timeout' => fakeVentSec(fn () => throw new ConnectionException('timeout')),
        'not-json' => fakeVentSec(fn () => Http::response('<html>', 200)),
        'no-signature' => fakeVentSec(array_diff_key($ok, ['signature' => 1])),
        'bad-signature' => fakeVentSec([...$ok, 'signature' => b64u(random_bytes(64))]),
        'wrong-key' => fakeVentSec(signedVerdict($contents, secret: sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))),
        'tampered' => fakeVentSec([...$ok, 'binary_verdict' => 'infected']),
        'wrong-sha' => fakeVentSec(signedVerdict($contents, ['sha256' => hash('sha256', 'masik')])),
        'wrong-size' => fakeVentSec(signedVerdict($contents, ['size' => 1])),
        'wrong-ref' => fakeVentSec(signedVerdict($contents, ['ref' => 'ventmail:masik'])),
        'wrong-profile' => fakeVentSec(signedVerdict($contents, ['profile' => 'asset-publish'])),
        'unavailable-verdict' => fakeVentSec(signedVerdict($contents, ['binary_verdict' => 'unavailable', 'verdict' => 'error', 'recommended_action' => 'quarantine'])),
        'unknown-verdict' => fakeVentSec(signedVerdict($contents, ['binary_verdict' => 'suspicious'])),
        'unknown-key' => fakeVentSec(signedVerdict($contents, ['key_id' => '0000000000000000'])),
        'keys-down' => fakeVentSec($ok, keys: []),
        'key-id-mismatch' => fakeVentSec($ok, keys: ['keys' => [jwkOf(test()->public, 'aaaaaaaaaaaaaaaa')]]),
    };

    if ($case === 'keys-down') {
        Http::swap(new Factory);
        Http::fake([
            'sso.test/*' => Http::response(['access_token' => 'tok.en.sig', 'expires_in' => 600]),
            'sec.test/.well-known/*' => Http::response('boom', 500),
            'sec.test/scan/v1/files' => Http::response($ok),
        ]);
    }

    $result = scanner()->scan('szamla.pdf', $contents);

    expect($result->status)->toBe('unavailable')->and($result->detail)->not->toBeEmpty();
})->with([
    '5xx', '429', '413', 'timeout', 'not-json', 'no-signature', 'bad-signature', 'wrong-key', 'tampered',
    'wrong-sha', 'wrong-size', 'wrong-ref', 'wrong-profile', 'unavailable-verdict', 'unknown-verdict',
    'unknown-key', 'keys-down', 'key-id-mismatch',
]);

it('m2m token nélkül unavailable, és a scan kérés el sem megy', function () {
    Http::fake(['sso.test/*' => Http::response(['error' => 'invalid_client'], 401), 'sec.test/*' => Http::response([])]);

    expect(scanner()->scan('a.pdf', SCAN_FILE)->status)->toBe('unavailable');

    Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'https://sec.test'));
});

it('a 25 MB feletti tartalmat nem küldi el (a szinkron API plafonja) — unavailable', function () {
    Http::fake();
    config(['ventsec.scan_max_bytes' => 10]);

    expect(scanner()->scan('a.bin', str_repeat('x', 11))->status)->toBe('unavailable');
    Http::assertNothingSent();
});

it('hiányzó konfigurációval nem küld, unavailable', function () {
    Http::fake();
    config(['ventsec.scan_url' => '']);

    expect(scanner()->scan('a.pdf', SCAN_FILE)->status)->toBe('unavailable');
    Http::assertNothingSent();
});

it('a közzétett kulcsot cache-eli; ismeretlen kid esetén egyszer újratölt (kulcsrotáció)', function () {
    $keyRequests = 0;
    $rotated = sodium_crypto_sign_keypair();
    $rotatedId = substr(hash('sha256', sodium_crypto_sign_publickey($rotated)), 0, 16);

    Http::fake([
        'sso.test/*' => Http::response(['access_token' => 'tok.en.sig', 'expires_in' => 600]),
        'sec.test/.well-known/*' => function () use (&$keyRequests, $rotated) {
            $keyRequests++;

            // Az első dokumentumban még csak a régi kulcs; az újratöltésnél már az új is.
            return Http::response(['keys' => $keyRequests === 1
                ? [jwkOf(test()->public)]
                : [jwkOf(test()->public), jwkOf(sodium_crypto_sign_publickey($rotated))]]);
        },
        'sec.test/scan/v1/files' => function (Request $request) use ($rotated, $rotatedId) {
            $body = $request->body();
            $useRotated = str_contains($body, 'ROTATED');

            return Http::response(signedVerdict($body, $useRotated
                ? ['key_id' => $rotatedId]
                : [], $useRotated ? sodium_crypto_sign_secretkey($rotated) : null));
        },
    ]);

    expect(scanner()->scan('a.pdf', SCAN_FILE)->status)->toBe('clean');
    expect(scanner()->scan('b.pdf', SCAN_FILE.'x')->status)->toBe('clean');
    expect($keyRequests)->toBe(1); // cache

    expect(scanner()->scan('c.pdf', 'ROTATED tartalom')->status)->toBe('clean');
    expect($keyRequests)->toBe(2);

    // Az újratöltés korlátozott: egy ismeretlen kid nem pörgeti a kulcs-végpontot.
    Http::swap(new Factory);
    Http::fake([
        'sso.test/*' => Http::response(['access_token' => 'tok.en.sig', 'expires_in' => 600]),
        'sec.test/.well-known/*' => function () use (&$keyRequests) {
            $keyRequests++;

            return Http::response(['keys' => [jwkOf(test()->public)]]);
        },
        'sec.test/scan/v1/files' => fn (Request $r) => Http::response(signedVerdict($r->body(), ['key_id' => '1111111111111111'])),
    ]);
    $before = $keyRequests;
    Cache::forget('ventsec:verdict-keys');
    for ($i = 0; $i < 5; $i++) {
        scanner()->scan('d.pdf', 'tartalom'.$i);
    }
    expect($keyRequests - $before)->toBeLessThanOrEqual(2);
});

it('csak EdDSA/OKP/Ed25519 kulcsot fogad el, a kid-nek a kulcs hash-ével egyeznie kell', function () {
    fakeVentSec(signedVerdict(SCAN_FILE), keys: ['keys' => [
        ['kid' => test()->keyId, 'kty' => 'RSA', 'x' => b64u(test()->public)],
        ['kid' => test()->keyId, 'kty' => 'OKP', 'crv' => 'X25519', 'x' => b64u(test()->public)],
        ['kid' => test()->keyId, 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => b64u('rovid')],
    ]]);

    expect(scanner()->scan('a.pdf', SCAN_FILE)->status)->toBe('unavailable');
});

it('a verdikt scan_id-ját a ScanResult hordozza (az események korrelációjához)', function () {
    fakeVentSec(signedVerdict(SCAN_FILE));

    expect(scanner()->scan('a.pdf', SCAN_FILE)->scanId)->toBe('01J9Z6Q7Y3X5R0K8ZC4M2T1H6B');
});

it('az Idempotency-Key a csatolmány-példányra szól (üzenet + sorszám), nem a tartalomra (S1)', function () {
    fakeVentSec(signedVerdict(SCAN_FILE));

    scanner()->scan('a.pdf', SCAN_FILE, 'msg-uuid-1-0');
    scanner()->scan('a.pdf', SCAN_FILE, 'msg-uuid-1-1');
    scanner()->scan('a.pdf', SCAN_FILE);

    $keys = Http::recorded()->filter(fn ($p) => str_ends_with($p[0]->url(), '/scan/v1/files'))
        ->map(fn ($p) => $p[0]->header('Idempotency-Key')[0])->values()->all();

    expect($keys[0])->toBe('vm-scan-msg-uuid-1-0')->and($keys[1])->toBe('vm-scan-msg-uuid-1-1')
        ->and($keys[2])->toStartWith('vm-scan-')->and($keys[2])->not->toBe($keys[0])
        ->and($keys[2])->not->toContain(hash('sha256', SCAN_FILE));

    foreach ($keys as $key) {
        foreach (['campaign:', 'journey:', 'template-test:', 'invite:', 'optin:', 'smtp:', 'inbound-fwd-', 'dev-seed-'] as $reserved) {
            expect($key)->not->toStartWith($reserved);
        }
    }
});

it('a régi vagy jövőbeli aláírt scanned_at unavailable (frissességi határ)', function (string $offset, bool $ok) {
    config(['ventsec.verdict_max_age' => 600]);
    fakeVentSec(signedVerdict(SCAN_FILE, ['scanned_at' => now()->modify($offset)->utc()->format('Y-m-d\TH:i:s.v\Z')]));

    expect(scanner()->scan('a.pdf', SCAN_FILE)->status)->toBe($ok ? 'clean' : 'unavailable');
})->with([['-5 seconds', true], ['-599 seconds', true], ['-601 seconds', false], ['-2 days', false], ['+10 minutes', false]]);

it('hibás scanned_at formátum unavailable', function () {
    fakeVentSec(signedVerdict(SCAN_FILE, ['scanned_at' => 'tegnap']));

    expect(scanner()->scan('a.pdf', SCAN_FILE)->status)->toBe('unavailable');
});

it('stale_signatures=true mellett a clean is unavailable (mail-inbound fail-closed)', function () {
    fakeVentSec(signedVerdict(SCAN_FILE, ['stale_signatures' => true, 'scanned_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z')]));

    expect(scanner()->scan('a.pdf', SCAN_FILE)->status)->toBe('unavailable');

    // A fertőzött ítélet elavult szignatúrával is fertőzött marad (a gyanú erősebb).
    fakeVentSec(signedVerdict('x', ['stale_signatures' => true, 'binary_verdict' => 'infected', 'verdict' => 'infected', 'steps' => [], 'scanned_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z')]));
    expect(scanner()->scan('a.bin', 'x')->status)->toBe('infected');
});

it('a kulcs-rögzítés (VENTSEC_VERDICT_KEY_PINS) csak a felsorolt kid-et fogadja el (S5)', function () {
    fakeVentSec(signedVerdict(SCAN_FILE));

    config(['ventsec.verdict_key_pins' => ['aaaaaaaaaaaaaaaa']]);
    expect(scanner()->scan('a.pdf', SCAN_FILE)->status)->toBe('unavailable');

    Cache::flush();
    config(['ventsec.verdict_key_pins' => ['aaaaaaaaaaaaaaaa', test()->keyId]]);
    expect(scanner()->scan('b.pdf', SCAN_FILE)->status)->toBe('clean');

    expect(configFileWith('ventsec', ['VENTSEC_VERDICT_KEY_PINS' => ' a1 , b2 ,'])['verdict_key_pins'])->toBe(['a1', 'b2'])
        ->and(configFileWith('ventsec', [])['verdict_key_pins'])->toBe([]);
});

it('a kulcs-letöltés hibája után hűtés van: nem pörgeti a végpontot (N3)', function () {
    $fetches = 0;
    Http::swap(new Factory);
    Http::fake([
        'sso.test/*' => Http::response(['access_token' => 'tok.en.sig', 'expires_in' => 600]),
        'sec.test/.well-known/*' => function () use (&$fetches) {
            $fetches++;

            return Http::response('boom', 500);
        },
        'sec.test/scan/v1/files' => fn (Request $r) => Http::response(signedVerdict($r->body())),
    ]);

    for ($i = 0; $i < 5; $i++) {
        expect(scanner()->scan('a.pdf', 'x'.$i)->status)->toBe('unavailable');
    }

    expect($fetches)->toBe(1);
});

it('az alapértelmezett konfiguráció: 600 s frissesség, 20 s szkennelési időkorlát', function () {
    $c = configFileWith('ventsec', []);

    expect($c['verdict_max_age'])->toBe(600)->and($c['scan_timeout'])->toBe(20);
});
