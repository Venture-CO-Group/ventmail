<?php

declare(strict_types=1);

use App\Modules\Domains\Services\DkimKeyManager;
use App\Modules\Domains\Services\DnsRecordMatcher;

/*
 | KAT-vektorok a DKIM kulcs-kódolásra és a TXT-összehasonlításra.
 |
 | Miért nem elég a korábbi „ellenőrzés": az újraimplementálta a tesztelt `str_replace`-t,
 | tehát a hiba MINDKÉT oldalon egyformán jelent volna meg, és a teszt zöld marad.
 | Itt fix kulcspár és LITERÁL elvárt érték van.
 */

function dkimFixture(string $name): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/'.$name));
}

it('a PEM-fejléc eltávolítása tiszta base64-et ad', function () {
    $expected = trim(dkimFixture('dkim-test-key.base64.txt'));

    $stripped = app(DkimKeyManager::class)->publicKeyForDns(dkimFixture('dkim-test-key.pub.pem'));

    expect($stripped)->toBe($expected);
    // Tiszta base64: se fejléc, se sortörés, se whitespace.
    expect($stripped)->toMatch('/^[A-Za-z0-9+\/=]+$/');
    expect(base64_decode($stripped, strict: true))->not->toBeFalse();
});

it('CRLF sorvégű PEM-et is helyesen kezel', function () {
    $crlf = str_replace("\n", "\r\n", dkimFixture('dkim-test-key.pub.pem'));

    expect(app(DkimKeyManager::class)->publicKeyForDns($crlf))
        ->toBe(trim(dkimFixture('dkim-test-key.base64.txt')));
});

it('PKCS#1 fejlécű PEM-et sem hagy benne a kimenetben', function () {
    $pkcs1 = "-----BEGIN RSA PUBLIC KEY-----\nMIIBCgKCAQEA\n-----END RSA PUBLIC KEY-----\n";

    expect(app(DkimKeyManager::class)->publicKeyForDns($pkcs1))->toBe('MIIBCgKCAQEA');
});

it('érvénytelen PEM esetén hangosan elszáll', function () {
    expect(fn () => app(DkimKeyManager::class)->publicKeyForDns('nem ez egy PEM!!!'))
        ->toThrow(RuntimeException::class);
});

dataset('dkim-osszehasonlitas', [
    'darabolt TXT' => [['v=DKIM1; k=rsa; ', 'p=%KEY%'], true],
    'egyben, idézőjelekkel' => [['"v=DKIM1; k=rsa; p=%KEY%"'], true],
    'extra whitespace' => [["v=DKIM1;  k=rsa;\n p=%KEY%"], true],
    'hiányzó v=DKIM1' => [['k=rsa; p=%KEY%'], false],
    'üres válasz' => [[], false],
    'idegen kulcs' => [['v=DKIM1; k=rsa; p=IDEGEN'], false],
]);

it('a DKIM TXT-összehasonlítás vektorai', function (array $found, bool $expected) {
    $key = trim(dkimFixture('dkim-test-key.base64.txt'));
    $found = array_map(fn (string $value) => str_replace('%KEY%', $key, $value), $found);

    expect(app(DnsRecordMatcher::class)->dkimMatches($found, $key))->toBe($expected);
})->with('dkim-osszehasonlitas');

it('a base64 kulcs összehasonlítása BÁJTRA pontos', function () {
    // A `p=` érték base64, ami case-SENSITIVE: a kisbetűsítés két eltérő bájtsorozatot
    // tenne egyenlővé.
    $key = trim(dkimFixture('dkim-test-key.base64.txt'));
    $tampered = strtolower($key);

    expect($key)->not->toBe($tampered, 'a fixture nem alkalmas a teszthez');
    expect(app(DnsRecordMatcher::class)->dkimMatches(['v=DKIM1; k=rsa; p='.$tampered], $key))->toBeFalse();
});

it('érvénytelen bájtsorozat nem eredményez fail-open egyezést', function () {
    // A `preg_replace` hibára futhat (PCRE-limit, érvénytelen UTF-8): a korábbi `?? ''`
    // mindkét oldalt üres sztringgé tette, és `'' === ''` VERIFIKÁLTAT jelentett.
    $key = trim(dkimFixture('dkim-test-key.base64.txt'));

    expect(app(DnsRecordMatcher::class)->dkimMatches(["\xC3\x28invalid"], $key))->toBeFalse();
    expect(app(DnsRecordMatcher::class)->dkimMatches([str_repeat('a', 100_000)], $key))->toBeFalse();
    expect(app(DnsRecordMatcher::class)->dkimMatches([''], ''))->toBeFalse();
});

dataset('spf-vektorok', [
    'sajátunk egyedül' => [['v=spf1 include:spf.ventmail.app ~all'], true],
    'meglévő SPF-be bővítve' => [['v=spf1 include:_spf.google.com include:spf.ventmail.app ~all'], true],
    'a mi include-unk nélkül' => [['v=spf1 include:_spf.google.com ~all'], false],
    'KÉT spf rekord (permerror)' => [['v=spf1 include:spf.ventmail.app ~all', 'v=spf1 -all'], false],
    'nem spf rekord' => [['google-site-verification=abc'], false],
]);

it('az SPF-vektorok', function (array $found, bool $expected) {
    config(['domains.spf_include' => 'spf.ventmail.app']);

    expect(app(DnsRecordMatcher::class)->spfMatches($found))->toBe($expected);
})->with('spf-vektorok');

dataset('dmarc-vektorok', [
    'pont az elvárt' => ['none', ['v=DMARC1; p=none; rua=mailto:x@y.z'], true],
    'szigorúbb elfogadva' => ['none', ['v=DMARC1; p=reject'], true],
    'gyengébb elutasítva' => ['quarantine', ['v=DMARC1; p=none'], false],
    'házirend nélkül' => ['none', ['v=DMARC1; rua=mailto:x@y.z'], false],
    'két DMARC rekord' => ['none', ['v=DMARC1; p=none', 'v=DMARC1; p=reject'], false],
]);

it('a DMARC-vektorok', function (string $required, array $found, bool $expected) {
    config(['domains.dmarc_policy' => $required]);

    expect(app(DnsRecordMatcher::class)->dmarcMatches($found))->toBe($expected);
})->with('dmarc-vektorok');
