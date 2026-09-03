<?php

declare(strict_types=1);

use App\Modules\Events\Data\TrackingClaim;
use App\Modules\Events\Http\Controllers\TrackingController;
use App\Modules\Events\Services\TrackingTokens;

/*
 | A NYOMKÖVETÉSI TOKEN KANONICITÁS-VEKTORAI.
 |
 | Ez a token egy új, kriptográfiát hordozó formátum: titkosít (a címzett és a klikk célja
 | nem olvasható), hitelesít (a `Crypt` MAC-je), és bemutatóra szóló hitelesítőként működik az
 | esemény-rögzítéshez. Az ilyen formátumnak nem elég, hogy a jó token működik — az is kell,
 | hogy MINDEN MÁS alak elbukjon.
 |
 | A konkrét veszély: a base64 nem-szigorú dekódolása átugorja a `=` karaktert a string
 | BELSEJÉBEN is. A `~` nálunk `=`-re fordul, tehát egyetlen elkapott tokenből több ezer olyan
 | variáns gyártható, ami UGYANARRA az igényre fejtődik meg. Ha bármelyik fék (dedup,
 | rate-limit) a token bájtjaira kulcsol, azt ezzel meg lehet kerülni — ami korlátlan
 | esemény-injektálást jelentene, és minden injektált esemény kimenő webhookot is indítana.
 |
 | Egy igény = EGY token.
 */

beforeEach(function () {
    $this->tokens = app(TrackingTokens::class);
});

it('a kiadott token visszafejthető', function () {
    $token = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test', 'email.pelda.hu', 'https://cel.example/x');

    $claim = $this->tokens->parse($token);

    expect($claim)->toBeInstanceOf(TrackingClaim::class);
    expect($claim->workspaceId)->toBe(7);
    expect($claim->messageUuid)->toBe('aaaa-bbbb');
    expect($claim->recipient)->toBe('cimzett@example.test');
    expect($claim->host)->toBe('email.pelda.hu');
    expect($claim->destination)->toBe('https://cel.example/x');
});

it('a MALLEÁLT token érvénytelen — a `~` beszúrása minden pozícióban', function () {
    $token = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test', 'email.pelda.hu');

    $elfogadott = [];

    // MINDEN pozíció, nem néhány minta: a nem-szigorú dekódolásnál 370 pozícióból 370
    // maradt érvényes.
    for ($i = 1; $i < strlen($token); $i++) {
        $mallealt = substr($token, 0, $i).'~'.substr($token, $i);

        if ($this->tokens->parse($mallealt) !== null) {
            $elfogadott[] = $i;
        }
    }

    expect($elfogadott)->toBe([]);
});

it('a token végére fűzött karakterek sem adnak érvényes variánst', function () {
    $token = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test', 'email.pelda.hu');

    foreach (['~', '~~', '-', '_', 'A'] as $utolag) {
        expect($this->tokens->parse($token.$utolag))->toBeNull();
    }
});

it('EGY igényhez EGY token tartozik — a lenyomat is ugyanaz', function () {
    // A dedup és a rate-limit ERRE kulcsol, nem a token bájtjaira: két kiadás ugyanarra az
    // igényre más bájtsort ad (a `Crypt` IV-t használ), a lenyomat viszont azonos.
    $a = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test', 'email.pelda.hu');
    $b = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test', 'email.pelda.hu');

    expect($a)->not->toBe($b);

    $lenyomatA = $this->tokens->fingerprint($this->tokens->parse($a));
    $lenyomatB = $this->tokens->fingerprint($this->tokens->parse($b));

    expect($lenyomatA)->toBe($lenyomatB);
});

it('a lenyomat MINDEN mezőre érzékeny', function () {
    $alap = fn (array $override = []) => $this->tokens->fingerprint(new TrackingClaim(
        workspaceId: $override['w'] ?? 7,
        messageUuid: $override['m'] ?? 'aaaa-bbbb',
        recipient: $override['r'] ?? 'cimzett@example.test',
        host: $override['h'] ?? 'email.pelda.hu',
        destination: $override['d'] ?? null,
    ));

    $referencia = $alap();

    expect($alap(['w' => 8]))->not->toBe($referencia);
    expect($alap(['m' => 'aaaa-bbbc']))->not->toBe($referencia);
    expect($alap(['r' => 'masik@example.test']))->not->toBe($referencia);
    expect($alap(['h' => 'email.masik.hu']))->not->toBe($referencia);
    expect($alap(['d' => 'https://cel.example/x']))->not->toBe($referencia);
});

it('a hamisított és a csonkolt token érvénytelen', function () {
    $token = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test', 'email.pelda.hu');

    expect($this->tokens->parse(substr($token, 0, -4)))->toBeNull();
    expect($this->tokens->parse(strrev($token)))->toBeNull();
    expect($this->tokens->parse('teljesen-hamis'))->toBeNull();
    expect($this->tokens->parse(''))->toBeNull();
    // Érvényes base64, de nem a mi rejtjelezésünk.
    expect($this->tokens->parse(strtr(base64_encode('{"w":7,"m":"x","r":"a@b.c","h":"h"}'), '+/=', '-_~')))->toBeNull();
});

/*
 | A KLIKK CÉLJA — vezérlőkarakter-vektorok.
 |
 | Az ügyfél `href`-jét entitás-dekódolás után tesszük a tokenbe: egy `&#13;&#10;` VALÓDI
 | CR/LF-fé válik. A `^https?://` prefix-ellenőrzés ezt átengedné, és a `Location` fejlécben
 | a mögötte álló szöveg KÜLÖN FEJLÉCSORKÉNT jelenne meg.
 */

it('a vezérlőkaraktert tartalmazó cél nem elfogadható', function (string $url) {
    expect(TrackingController::safeDestination($url))->toBeFalse();
})->with([
    'CRLF + fejléc' => "https://jo.example/\r\nX-Injektalt: 1",
    'csak LF' => "https://jo.example/\nSet-Cookie: a=b",
    'csak CR' => "https://jo.example/\rLocation: https://tamado.example",
    'NUL' => "https://jo.example/\0",
    'TAB' => "https://jo.example/\tX: 1",
    'nem http séma' => 'javascript:alert(1)',
    'adat-URL' => 'data:text/html,<script>alert(1)</script>',
    'hoszt nélkül' => 'https://',
    'üres' => '',
]);

it('a rendes cél viszont elfogadható', function () {
    expect(TrackingController::safeDestination('https://ugyfel.example.test/szamla/42?a=1#x'))->toBeTrue();
    expect(TrackingController::safeDestination('http://ugyfel.example.test/x'))->toBeTrue();
});
