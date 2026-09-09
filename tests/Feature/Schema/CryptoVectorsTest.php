<?php

declare(strict_types=1);

use App\Modules\Audit\Services\KeyedHash;

/*
 | KAT (known-answer test) vektorok a kulcsolt hashekhez.
 |
 | Miért kell: egy bcrypt → HMAC váltás credential-verifieren kripto-változás. Ha egy
 | későbbi refaktor csendben megváltoztatja a bemenet normalizálását, a pepper forrását
 | vagy a domain-elválasztást, az ÖSSZES API-kulcs egyszerre érvénytelenné válik — és ezt
 | fix vektorok nélkül semmi nem fogná meg.
 */

beforeEach(function () {
    config([
        'audit.pepper' => 'teszt-pepper-0123456789abcdef0123456789abcdef',
        'audit.previous_pepper' => '',
    ]);
});

dataset('vektorok', [
    ['api_key', 'vm_live_teszt'],
    ['email', 'cimzett@example.test'],
    ['ip', '203.0.113.7'],
    // A B4 klikk-térkép link-kulcsa. A domain-elválasztás itt is számít: a link-hash és az
    // e-mail-hash összevethető lenne, ha ugyanabból a kulcsból származna.
    ['click-url', 'https://pelda.hu/akcio'],
]);

it('a kulcsolt hash determinisztikus és domain-elválasztott', function (string $purpose, string $input) {
    $hash = KeyedHash::of($purpose, $input);

    // Determinisztikus: ugyanaz a bemenet ugyanazt adja.
    expect(KeyedHash::of($purpose, $input))->toBe($hash);

    // Domain-elválasztás: MÁS célra ugyanaz a bemenet MÁS hasht ad — enélkül egy
    // e-mail-hash és egy API-kulcs-hash összevethető lenne.
    expect(KeyedHash::of($purpose === 'api_key' ? 'email' : 'api_key', $input))->not->toBe($hash);

    expect($hash)->toHaveLength(64);
})->with('vektorok');

it('rögzített KAT-vektor: a hash értéke nem csúszhat el csendben', function () {
    // Fix pepper + fix bemenet → fix kimenet. Ha egy refaktor megváltoztatja a
    // normalizálást vagy a domain-elválasztást, ez a teszt azonnal jelez.
    expect(KeyedHash::of('api_key', 'vm_live_teszt'))
        ->toBe(hash_hmac('sha256', 'vm_live_teszt',
            hash_hmac('sha256', 'ventmail:api_key', 'teszt-pepper-0123456789abcdef0123456789abcdef', true)));
});

it('a pepper hiánya hangos hiba, nem csendes gyenge hash', function () {
    config(['audit.pepper' => '']);

    expect(fn () => KeyedHash::of('email', 'x@example.test'))->toThrow(RuntimeException::class);
});

it('a túl rövid pepper elutasítva', function () {
    config(['audit.pepper' => 'rovid']);

    expect(fn () => KeyedHash::of('email', 'x@example.test'))->toThrow(RuntimeException::class);
});

it('rotáció közben a korábbi pepper is ellenőrizhető', function () {
    $regi = KeyedHash::of('api_key', 'vm_live_kulcs');

    config([
        'audit.pepper' => 'uj-pepper-0123456789abcdef0123456789abcdef00',
        'audit.previous_pepper' => 'teszt-pepper-0123456789abcdef0123456789abcdef',
    ]);

    // Az új pepper más hasht ad...
    expect(KeyedHash::of('api_key', 'vm_live_kulcs'))->not->toBe($regi);

    // ...de az átfedéses ideig a régi is elfogadott, különben a rotáció EGYSZERRE
    // érvénytelenítené az összes ügyfél összes kulcsát.
    expect(KeyedHash::matches('api_key', 'vm_live_kulcs', $regi))->toBeTrue();
});
