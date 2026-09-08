<?php

declare(strict_types=1);

use App\Modules\Events\Services\TrackingTokens;
use App\Modules\Suppression\Services\UnsubscribeTokens;

/*
 | A LEIRATKOZÓ TOKEN VEKTORAI.
 |
 | Ez a token új, kriptográfiát hordozó formátum, és a rendszer LEGÉRZÉKENYEBB bemutatóra
 | szóló hitelesítője: a birtokosa ír egy olyan suppression-sorra, amit a tenant API-ból nem
 | is vehet le (`SuppressionReason::Unsubscribe::isIrrevocableByTenant()`). Az ilyen
 | formátumnak nem elég, hogy a jó token működik — az is kell, hogy minden más alak elbukjon,
 | és hogy a MÁS CÉLRA kiadott tokenünk itt ne legyen érvényes.
 |
 | A vektorok két csoportba esnek, és a második a fontosabb:
 |
 |  1. KANONICITÁS — csonkolás, hamisított MAC, belső `=`, levágott padding, nem nulla záró
 |     bitek. Ezeket a `parse()` kanonikus-base64 ellenőrzése zárja.
 |  2. AMIT A KANONICITÁS NEM ZÁR — a `Crypt`-boríték JSON-ja újraszerializálható (a MAC csak
 |     az `iv`+`value` fölött van), és a `Crypt` IV-t használ. Vagyis ugyanarra az igényre
 |     VÉGTELEN sok érvényes bájtsor létezik. Ez a vektor azért van itt, hogy senki ne írjon
 |     a token BÁJTJAIRA kulcsoló féket: az születetten megkerülhető lenne.
 */

beforeEach(function () {
    $this->tokens = app(UnsubscribeTokens::class);
});

it('a kiadott token visszafejthető', function () {
    $token = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test');

    expect($this->tokens->parse($token))->toBe([
        'workspaceId' => 7,
        'messageUuid' => 'aaaa-bbbb',
        'recipient' => 'cimzett@example.test',
    ]);
});

it('a token URL-biztos ábécét használ', function () {
    // A `+/=` karakterek útvonal-szegmensben nem használhatók; a route-szűrő ábécéje
    // (`[A-Za-z0-9\-_~]`) ennél nem lehet szűkebb, különben a link 404-et adna.
    for ($i = 0; $i < 50; $i++) {
        $token = $this->tokens->issue($i, 'm-'.$i, 'a'.$i.'@example.test');

        expect(preg_match('/^[A-Za-z0-9\-_~]+$/', $token))->toBe(1);
    }
});

it('a NYOMKÖVETÉSI token NEM érvényes leiratkozó token', function () {
    // Ez a legfontosabb vektor. Az `APP_KEY` közös, és a `TrackingTokens` ugyanazt a
    // `w`/`m`/`r` hármast adja ki ugyanabban a borítékban. Cél-elválasztás nélkül a levél
    // MINDEN linkje visszavonhatatlan one-click leiratkozás volt — a nyomkövetési tokent
    // pedig a védelmi átjárók végiglátogatják ÉS naplózzák, a Gmail képproxyja lekéri, és
    // továbbított levélben is utazik.
    $clickToken = app(TrackingTokens::class)
        ->issue(7, 'aaaa-bbbb', 'cimzett@example.test', 'email.pelda.hu', 'https://cel.example/x');

    $openToken = app(TrackingTokens::class)
        ->issue(7, 'aaaa-bbbb', 'cimzett@example.test', 'email.pelda.hu');

    expect($this->tokens->parse($clickToken))->toBeNull();
    expect($this->tokens->parse($openToken))->toBeNull();
});

it('a LEIRATKOZÓ token NEM érvényes nyomkövetési token', function () {
    // A visszirány is zárva — és nem csak a `h` mező hiánya miatt, hanem a célon is.
    $token = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test');

    expect(app(TrackingTokens::class)->parse($token))->toBeNull();
});

it('a CSONKOLT token érvénytelen', function () {
    $token = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test');

    foreach ([1, 8, 40, strlen($token) - 4, strlen($token) - 1] as $length) {
        expect($this->tokens->parse(substr($token, 0, $length)))->toBeNull();
    }
});

it('a HAMISÍTOTT MAC érvénytelen', function () {
    // A `Crypt` MAC-je nélkül a payload átírható lenne: egy támadó a `r` mezőt cserélve
    // bárkit leiratkoztathatna.
    $token = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test');

    $envelope = json_decode((string) base64_decode(strtr($token, '-_~', '+/='), true), true);
    $envelope['mac'] = str_repeat('0', strlen((string) $envelope['mac']));

    $forged = strtr(base64_encode((string) json_encode($envelope)), '+/=', '-_~');

    expect($this->tokens->parse($forged))->toBeNull();
});

it('az ÁTÍRT payload érvénytelen — más címzettre nem szól a token', function () {
    $token = $this->tokens->issue(7, 'aaaa-bbbb', 'anna@example.test');

    $envelope = json_decode((string) base64_decode(strtr($token, '-_~', '+/='), true), true);

    // A `value` a titkosított payload: egyetlen bájtjának átírása is elbuktatja a MAC-et.
    $value = base64_decode((string) $envelope['value'], true);
    $value[0] = $value[0] === "\x00" ? "\x01" : "\x00";
    $envelope['value'] = base64_encode((string) $value);

    $tampered = strtr(base64_encode((string) json_encode($envelope)), '+/=', '-_~');

    expect($this->tokens->parse($tampered))->toBeNull();
});

it('a BELSŐ `=` érvénytelen — a nem-szigorú base64 átugorná', function () {
    // A `~` nálunk `=`-re fordul. A nem-szigorú dekódolás a string BELSEJÉBEN is átugorja,
    // tehát enélkül egyetlen elkapott tokenből több ezer variáns lenne gyártható.
    $token = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test');

    $accepted = [];

    for ($i = 1; $i < strlen($token); $i++) {
        if ($this->tokens->parse(substr($token, 0, $i).'~'.substr($token, $i)) !== null) {
            $accepted[] = $i;
        }
    }

    expect($accepted)->toBe([]);
});

it('a LEVÁGOTT padding érvénytelen', function () {
    // A szigorú `base64_decode` ezt önmagában ÁTENGEDI — a kanonikus alak
    // (dekódol → visszakódol → bájtra egyeztet) zárja.
    $token = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test');

    $trimmed = rtrim($token, '~');

    expect($trimmed)->not->toBe($token);
    expect($this->tokens->parse($trimmed))->toBeNull();
});

it('a NEM NULLA záró bitek érvénytelenek', function () {
    // A padding hosszából adódik, hány bit használatlan (1 `=` → 2 bit, 2 `=` → 4 bit). Az
    // utolsó hasznos karakter olyan variánsa, ami ugyanazokra a hasznos bitekre dekódol,
    // más bájtsor — de ugyanaz az igény. A szigorú dekódolás ezt is átengedi.
    $token = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test');

    $trimmed = rtrim($token, '~');
    $padding = strlen($token) - strlen($trimmed);

    expect($padding)->toBeGreaterThan(0);

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $index = (int) strpos($alphabet, $trimmed[strlen($trimmed) - 1]);

    // Az utolsó karakter LEGALSÓ bitjének átfordítása: a hasznos bitek nem változnak.
    $variant = $alphabet[$index ^ 1];

    expect($this->tokens->parse(substr($trimmed, 0, -1).$variant.str_repeat('~', $padding)))
        ->toBeNull();
});

it('a BORÍTÉK ÚJRASZERIALIZÁLÁSA ÉRVÉNYES MARAD — ezért nem kulcsolhat fék a bájtokra', function () {
    // Ez a vektor NEM azt bizonyítja, hogy jól védünk: azt rögzíti, hogy MIT NEM védünk.
    //
    // A Laravel `Crypt` borítéka egy JSON-objektum (`iv`, `value`, `mac`, `tag`), és a MAC
    // csak az `iv`+`value` fölött van. A boríték tehát szabadon újraszerializálható —
    // kulcssorrend, whitespace, extra kulcs —, és minden változat ugyanarra az igényre
    // fejtődik meg. A kanonikus base64 ezt NEM zárja, és nem is tudja.
    //
    // A következtetés, amit ez a teszt őriz: minden fék (rate-limit) a `fingerprint()`-re
    // kulcsol, az IGÉNYRE. Aki ezt a tokenre cserélné, egy `json_encode`-dal kikapcsolható
    // féket írna — és ez a végpont visszavonhatatlan suppression-sort ír.
    $token = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test');

    $envelope = json_decode((string) base64_decode(strtr($token, '-_~', '+/='), true), true);

    $variants = [
        json_encode(array_reverse($envelope, true)),
        json_encode($envelope, JSON_PRETTY_PRINT),
        json_encode($envelope + ['pad' => str_repeat('x', 17)]),
    ];

    $fingerprints = [];

    foreach ($variants as $variant) {
        $rebuilt = strtr(base64_encode((string) $variant), '+/=', '-_~');

        expect($this->tokens->parse($rebuilt))->not->toBeNull();

        $fingerprints[] = $this->tokens->fingerprint($rebuilt);
    }

    // …és MINDEGYIK ugyanazt a lenyomatot adja: a fék így nem kerülhető meg.
    expect(array_unique($fingerprints))->toHaveCount(1);
    expect($fingerprints[0])->toBe($this->tokens->fingerprint($token));
});

it('UGYANARRA az igényre két kiadás KÜLÖNBÖZŐ tokent ad, de EGY lenyomatot', function () {
    // A `Crypt` IV-t használ. Ezért nincs „egyetlen érvényes alak" — és ezért kell az
    // igényre kulcsolni.
    $a = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test');
    $b = $this->tokens->issue(7, 'aaaa-bbbb', 'cimzett@example.test');

    expect($a)->not->toBe($b);
    expect($this->tokens->fingerprint($a))->toBe($this->tokens->fingerprint($b));
});

it('a lenyomat KÜLÖNBÖZŐ igényekre különbözik', function () {
    $base = $this->tokens->fingerprint($this->tokens->issue(7, 'aaaa-bbbb', 'anna@example.test'));

    expect($this->tokens->fingerprint($this->tokens->issue(8, 'aaaa-bbbb', 'anna@example.test')))->not->toBe($base);
    expect($this->tokens->fingerprint($this->tokens->issue(7, 'cccc-dddd', 'anna@example.test')))->not->toBe($base);
    expect($this->tokens->fingerprint($this->tokens->issue(7, 'aaaa-bbbb', 'bela@example.test')))->not->toBe($base);
});

it('az értelmezhetetlen tokenre a lenyomat NULL, nem kitalált érték', function () {
    // A rate-limiter ebből tudja, hogy nincs mihez kötni — és a valódi TCP-partnerre esik
    // vissza. Egy kitalált „üres" lenyomat MINDEN hibás tokent egy bucketbe tenne, és egy
    // szemetet küldő támadó a saját keretével fogyasztaná el másokét.
    expect($this->tokens->fingerprint('teljesen-hamis'))->toBeNull();
    expect($this->tokens->fingerprint(''))->toBeNull();
});
