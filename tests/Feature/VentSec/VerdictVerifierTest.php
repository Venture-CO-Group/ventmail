<?php

declare(strict_types=1);

use App\Modules\VentSec\Services\VerdictVerifier;
use App\Modules\VentSec\Support\Jcs;

/*
 | FO2 — a ventSec ítélet-aláírásának ellenőrzése a ventSec FÜGGETLEN (RFC 8032) vektorán.
 | A vektor a ventSec `tests/Vectors/scan-verdict.json`-ja; a kanonikus alakot és az aláírást
 | is a ventSec generátora adta, nem a mi kódunk.
 */

function verdictVectors(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/ventsec/scan-verdict.json')), true, 512, JSON_THROW_ON_ERROR);
}

/** A kliens az ítéletet stdClass-ként olvassa (`{}` és `[]` megkülönböztetéséhez). */
function verdictResponse(array $vector, ?string $signatureHex = null, array $override = []): stdClass
{
    $body = json_decode((string) json_encode($vector['payload']), false);
    $body->signature = b64u((string) hex2bin($signatureHex ?? $vector['signature_hex']));

    foreach ($override as $key => $value) {
        $body->{$key} = $value;
    }

    return $body;
}

it('a JCS-kanonikus alak bájtra egyezik a ventSec vektorával', function () {
    foreach (verdictVectors()['vectors'] as $vector) {
        $payload = json_decode((string) json_encode($vector['payload']), false);

        expect(Jcs::canonicalize($payload))->toBe($vector['canonical'], $vector['name']);
    }
});

it('a vektor mindhárom ítéletének aláírása érvényes', function () {
    $doc = verdictVectors();
    $key = (string) hex2bin($doc['public_key_hex']);

    foreach ($doc['vectors'] as $vector) {
        expect(app(VerdictVerifier::class)->verify(verdictResponse($vector), $key))->toBeTrue($vector['name']);
    }
});

it('a nem aláírt szállítási mezők (cached, stale, declared_ext) nem számítanak az aláírásba', function () {
    $doc = verdictVectors();
    $key = (string) hex2bin($doc['public_key_hex']);

    $response = verdictResponse($doc['vectors'][0], override: ['cached' => true, 'stale' => true, 'declared_ext' => 'pdf']);

    expect(app(VerdictVerifier::class)->verify($response, $key))->toBeTrue();
});

it('bármely aláírt mező átírása érvénytelenné teszi az aláírást', function () {
    $doc = verdictVectors();
    $key = (string) hex2bin($doc['public_key_hex']);
    $verifier = app(VerdictVerifier::class);

    $tampered = [
        'binary_verdict' => 'infected',
        'sha256' => str_repeat('a', 64),
        'size' => 1,
        'recommended_action' => 'reject',
        'flagged' => true,
        'scanned_at' => '2026-10-13T09:14:03.413Z',
    ];

    foreach ($tampered as $field => $value) {
        $response = verdictResponse($doc['vectors'][0], override: [$field => $value]);

        expect($verifier->verify($response, $key))->toBeFalse("a(z) {$field} módosítása elfogadva");
    }

    $infected = verdictResponse($doc['vectors'][1], override: ['binary_verdict' => 'clean', 'verdict' => 'clean']);
    expect($verifier->verify($infected, $key))->toBeFalse();
});

it('a hiányzó aláírt mező vagy a hozzáadott ref érvénytelen', function () {
    $doc = verdictVectors();
    $key = (string) hex2bin($doc['public_key_hex']);

    $missing = verdictResponse($doc['vectors'][0]);
    unset($missing->steps);
    expect(app(VerdictVerifier::class)->verify($missing, $key))->toBeFalse();

    // A második vektornak nincs `ref`-je: a hozzáadott ref más üzenet.
    $added = verdictResponse($doc['vectors'][1], override: ['ref' => 'att_x']);
    expect(app(VerdictVerifier::class)->verify($added, $key))->toBeFalse();
});

it('rossz kulccsal, hibás vagy hiányzó aláírással sosem érvényes — kivétel nélkül', function () {
    $doc = verdictVectors();
    $key = (string) hex2bin($doc['public_key_hex']);
    $verifier = app(VerdictVerifier::class);
    $vector = $doc['vectors'][0];

    $other = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
    expect($verifier->verify(verdictResponse($vector), $other))->toBeFalse();
    expect($verifier->verify(verdictResponse($vector), 'rovid'))->toBeFalse();

    foreach (['', 'nem-base64!', str_repeat('A', 86), str_repeat('A', 87), b64u(random_bytes(63))] as $bad) {
        $response = verdictResponse($vector);
        $response->signature = $bad;
        expect($verifier->verify($response, $key))->toBeFalse("elfogadva: {$bad}");
    }

    $response = verdictResponse($vector);
    unset($response->signature);
    expect($verifier->verify($response, $key))->toBeFalse();

    // Nem string aláírás.
    $response = verdictResponse($vector);
    $response->signature = ['x'];
    expect($verifier->verify($response, $key))->toBeFalse();

    // Padding-gel kódolt aláírás sem fogadható el (a ventSec padding nélkülit ad).
    $response = verdictResponse($vector);
    $response->signature = $response->signature.'==';
    expect($verifier->verify($response, $key))->toBeFalse();
});

it('a lebegőpontos szám az aláírt mezőben nem kanonizálható — hamis, nem kivétel', function () {
    $doc = verdictVectors();
    $key = (string) hex2bin($doc['public_key_hex']);

    $response = verdictResponse($doc['vectors'][0], override: ['size' => 68.5]);

    expect(app(VerdictVerifier::class)->verify($response, $key))->toBeFalse();
});

it('a JCS a ventSec jcs-vektorain is egyezik', function () {
    $path = base_path('tests/Fixtures/ventsec/jcs.json');
    $doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    foreach ($doc['jcs']['valid'] as $case) {
        // A vektor beágyazott objektumai tömbként jönnek: az üres `{}` itt `[]`-ként
        // olvasódna, ezért stdClass-szal olvassuk újra a nyers JSON-t.
        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $input = collect($decoded->jcs->valid)->firstWhere('name', $case['name'])->input;

        expect(Jcs::canonicalize($input))->toBe($case['canonical'], $case['name']);
    }
});

it('a JCS a ventSec „invalid" eseteit visszautasítja, nem kerekíti', function () {
    $doc = json_decode((string) file_get_contents(base_path('tests/Fixtures/ventsec/jcs.json')), false, 512, JSON_THROW_ON_ERROR);

    foreach ($doc->jcs->invalid as $case) {
        $input = json_decode($case->json, false, 512, JSON_THROW_ON_ERROR);

        expect(fn () => Jcs::canonicalize($input))->toThrow(InvalidArgumentException::class);
    }
});
