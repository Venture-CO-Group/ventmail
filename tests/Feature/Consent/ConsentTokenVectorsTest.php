<?php

declare(strict_types=1);

use App\Modules\Consent\Services\ConfirmationTokens;
use App\Modules\Consent\Services\PreferenceTokens;
use App\Modules\Events\Services\TrackingTokens;
use App\Modules\Suppression\Services\UnsubscribeTokens;
use Illuminate\Support\Facades\Crypt;

/*
 | A CONSENT-TOKENEK VEKTORAI (B1).
 |
 | Két új, kriptográfiát hordozó wire-formátum született, és mindkettő ÍRÓ képesség:
 |
 |  - `optin` (`/c/{token}`) — JOGALAPOT teremt. Ez a rendszer legérzékenyebb tokenje: aki
 |    beváltja, azzal a ventMail azt állítja, hogy az érintett hozzájárult a marketinghez;
 |  - `prefs` (`/p/{token}`) — listákat kapcsol, és teljes leiratkozást is végez.
 |
 | Az A6 négy review-köre után a szabály nem alku: kripto-változás beégetett KAT-vektor
 | nélkül blokkoló. A minta a `UnsubscribeTokenVectorsTest` és a `TrackingTokenVectorsTest`;
 | ez a fájl ugyanazt teszi a másik két célra, és lezárja a 4×4-es cél-mátrixot.
 */

/*
 | 1. A CÉL-MÁTRIX — mind a 12 kereszt-kombináció.
 |
 | Az `APP_KEY` KÖZÖS. Cél-elválasztás nélkül a négy token felcserélhető lenne, és az A6-ban
 | pontosan ez történt: a nyomkövetési token érvényes LEIRATKOZÓ token volt. Itt a kár még
 | nagyobb lenne: egy elkapott nyomkövetési tokennel (ami minden `href`-ben ott van, és amit
 | a proxyk NAPLÓZNAK) bárki „hozzájárulást" gyárthatna valaki más nevében.
 */
it('a négy token CÉLJA nem felcserélhető — mind a 12 irányban', function () {
    $workspace = 7;
    $email = 'cimzett@example.test';

    $tokens = [
        'unsub' => app(UnsubscribeTokens::class)->issue($workspace, '01a08000-0000-7000-8000-000000000000', $email),
        'track' => app(TrackingTokens::class)->issue($workspace, 'aaaa-bbbb', $email, 'email.pelda.hu'),
        'optin' => app(ConfirmationTokens::class)->issue($workspace, $email, 'marketing'),
        'prefs' => app(PreferenceTokens::class)->issue($workspace, $email),
    ];

    $parsers = [
        'unsub' => fn (string $token) => app(UnsubscribeTokens::class)->parse($token),
        'track' => fn (string $token) => app(TrackingTokens::class)->parse($token),
        'optin' => fn (string $token) => app(ConfirmationTokens::class)->parse($token),
        'prefs' => fn (string $token) => app(PreferenceTokens::class)->parse($token),
    ];

    foreach ($parsers as $purpose => $parse) {
        foreach ($tokens as $issued => $token) {
            $result = $parse($token);

            $issued === $purpose
                ? expect($result)->not->toBeNull("{$issued} → {$purpose}")
                : expect($result)->toBeNull("{$issued} → {$purpose}");
        }
    }
});

/*
 | 2. KANONICITÁS — mindkét új formátumon.
 */

dataset('consent-tokenek', [
    'optin' => [ConfirmationTokens::class, 'optin'],
    'prefs' => [PreferenceTokens::class, 'prefs'],
]);

function issueFor(string $class): string
{
    return $class === ConfirmationTokens::class
        ? app(ConfirmationTokens::class)->issue(7, 'cimzett@example.test', 'marketing')
        : app(PreferenceTokens::class)->issue(7, 'cimzett@example.test');
}

function parseFor(string $class, string $token): ?array
{
    return app($class)->parse($token);
}

it('a CSONKOLT token érvénytelen', function (string $class) {
    $token = issueFor($class);

    foreach ([1, 8, 40, strlen($token) - 4, strlen($token) - 1] as $length) {
        expect(parseFor($class, substr($token, 0, $length)))->toBeNull();
    }
})->with('consent-tokenek');

it('a BELSŐ `=` érvénytelen — a nem-szigorú base64 átugorná', function (string $class) {
    // A `~` nálunk `=`-re fordul. Enélkül egyetlen elkapott tokenből több ezer variáns
    // lenne gyártható, és minden token-bájtokra kulcsolt fék megkerülhető lenne.
    $token = issueFor($class);

    $accepted = [];

    for ($i = 1; $i < strlen($token); $i++) {
        if (parseFor($class, substr($token, 0, $i).'~'.substr($token, $i)) !== null) {
            $accepted[] = $i;
        }
    }

    expect($accepted)->toBe([]);
})->with('consent-tokenek');

it('a HAMISÍTOTT MAC és az ÁTÍRT payload érvénytelen', function (string $class) {
    $token = issueFor($class);

    $envelope = json_decode((string) base64_decode(strtr($token, '-_~', '+/='), true), true);

    $forged = $envelope;
    $forged['mac'] = str_repeat('0', strlen((string) $envelope['mac']));

    expect(parseFor($class, strtr(base64_encode((string) json_encode($forged)), '+/=', '-_~')))->toBeNull();

    $tampered = $envelope;
    $value = base64_decode((string) $envelope['value'], true);
    $value[0] = $value[0] === "\x00" ? "\x01" : "\x00";
    $tampered['value'] = base64_encode((string) $value);

    expect(parseFor($class, strtr(base64_encode((string) json_encode($tampered)), '+/=', '-_~')))->toBeNull();
})->with('consent-tokenek');

it('a BORÍTÉK ÚJRASZERIALIZÁLÁSA érvényes marad — ezért kulcsol minden fék a LENYOMATRA', function (string $class) {
    // Ez a vektor azt rögzíti, MIT NEM védünk: a `Crypt`-boríték JSON-ja szabadon
    // újraszerializálható (a MAC csak az `iv`+`value` fölött van), és a `Crypt` IV-t
    // használ. Vagyis ugyanarra az igényre VÉGTELEN sok érvényes bájtsor létezik — aki a
    // fékét a token bájtjaira kulcsolná, egy `json_encode`-dal kikapcsolhatóra írná.
    $token = issueFor($class);

    $envelope = json_decode((string) base64_decode(strtr($token, '-_~', '+/='), true), true);

    $variants = [
        json_encode(array_reverse($envelope, true)),
        json_encode($envelope, JSON_PRETTY_PRINT),
        json_encode($envelope + ['pad' => str_repeat('x', 17)]),
    ];

    $fingerprints = [];

    foreach ($variants as $variant) {
        $rebuilt = strtr(base64_encode((string) $variant), '+/=', '-_~');

        expect(parseFor($class, $rebuilt))->not->toBeNull();

        $fingerprints[] = app($class)->fingerprint($rebuilt);
    }

    expect(array_unique($fingerprints))->toHaveCount(1);
    expect($fingerprints[0])->toBe(app($class)->fingerprint($token));
})->with('consent-tokenek');

it('UGYANARRA az igényre két kiadás KÜLÖNBÖZŐ tokent ad, de EGY lenyomatot', function (string $class) {
    $a = issueFor($class);
    $b = issueFor($class);

    expect($a)->not->toBe($b);
    expect(app($class)->fingerprint($a))->toBe(app($class)->fingerprint($b));
})->with('consent-tokenek');

it('az értelmezhetetlen tokenre a lenyomat NULL, nem kitalált érték', function (string $class) {
    // A rate-limiter ebből tudja, hogy nincs mihez kötni, és a valódi TCP-partnerre esik
    // vissza. Egy kitalált „üres" lenyomat MINDEN hibás tokent egy vödörbe tenne, és egy
    // szemetet küldő támadó a saját keretével fogyasztaná el a legitim felhasználókét.
    expect(app($class)->fingerprint('teljesen-hamis'))->toBeNull();
    expect(app($class)->fingerprint(''))->toBeNull();
})->with('consent-tokenek');

it('a LEJÁRT token ugyanaz a válasz, mint a hamis', function (string $class) {
    // A megerősítő linknek VAN lejárata (szemben a leiratkozóval): egy hónapokkal későbbi
    // kattintás nem bizonyítja, hogy a felhasználó MOST is akarja, és a GDPR szerinti
    // hozzájárulás „konkrét és tájékozott".
    $token = issueFor($class);

    expect(parseFor($class, $token))->not->toBeNull();

    test()->travel(200 * 24)->hours();

    expect(parseFor($class, $token))->toBeNull();
})->with('consent-tokenek');

/*
 | 3. BEÉGETETT KAT-VEKTOROK — a wire-formátum rögzítése.
 */

it('a BEÉGETETT `optin` KAT-vektor: a wire-formátum nem változhat csendben', function () {
    // Ez a vektor a FORMÁTUMOT rögzíti, nem az implementációt ismétli meg. Ha egy jövőbeli
    // változás (új mező, más cél-jelölés) érvénytelenné teszi a régi tokeneket, az CSENDES
    // kár: a kiküldött megerősítő levelek linkje elhal, a beiratkozók jogalap nélkül
    // maradnak — és a `/c/` szándékosan nem ad orákulumot, tehát a felhasználó a megerősítő
    // oldalt látja, miközben semmi nem történik.
    //
    // Ha ez a teszt elpirul, a helyes lépés NEM a vektor átírása, hanem az átmeneti ág.
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);

    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstances();

    // A tokenben LEJÁRAT van, ezért fix időpontra állunk — a vektor így determinisztikus.
    test()->travelTo('2026-09-09 12:00:00');

    $token = 'eyJpdiI6IjY3bnc0MW1KR0l2TklkK1pqK0ZSVVE9PSIsInZhbHVlIjoiZEpVSmdEYVpiRkY2Q29NR0J4'
        .'akxYVDNkSUYrM2R5cGFjam1lV3RrWkhlRVlBMnlxa1pJWGFvTHQyWG8yUUcyejVKOSszSFFZRktwMlhZ'
        .'NXRMcGY3aVcreWNtOUJuZEJQNGZLMjFjVy9qR0l3SUpxU1doeDQzU3RGM2tYdzFDWXl5VHRobjVpRjJE'
        .'R0N0WHJHbEhIQkFnPT0iLCJtYWMiOiI0MTlkZTM2MDFiZjc1MDQ5ZTY4ZjAzYjVkYWNkY2Q5ODE0YmM0'
        .'MTc5ZTZkMGY3YjkzZDhkN2RlZjI4NGQyMTRhIiwidGFnIjoiIn0=';

    expect(app(ConfirmationTokens::class)->parse(strtr($token, '+/=', '-_~')))->toBe([
        'workspaceId' => 7,
        'email' => 'cimzett@example.test',
        'channel' => 'marketing',
        'listUuid' => 'aaaa-bbbb',
    ]);
});

it('a BEÉGETETT `prefs` KAT-vektor', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);

    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstances();

    test()->travelTo('2026-09-09 12:00:00');

    $token = 'eyJpdiI6ImRjTVR5WnpRZHovNmppQ3lhWmZoZFE9PSIsInZhbHVlIjoiM2pJVlFnUFJhRWMwTDVURGt4'
        .'VW13dUpNNGdoWCs0WW5qejI1eTZZeXRrWU9vL042VjFuMGNIYzZsMldtaGZCaEwzU29xcHJpZjVObENK'
        .'emUwRjBSTnc3M3lsb3VENVB0ZlV0K2VDenpkeEE9IiwibWFjIjoiN2VmZDVhZDQxYzRkMGI3NzY5NGY2'
        .'NDBkZGUxZWM1ODA5YjU4NGE3MDNlYWU0MDk4MzQ2OTk3YzA5MzgxZmI3OSIsInRhZyI6IiJ9';

    expect(app(PreferenceTokens::class)->parse(strtr($token, '+/=', '-_~')))->toBe([
        'workspaceId' => 7,
        'email' => 'cimzett@example.test',
    ]);
});

it('a `p`/`v` NÉLKÜLI, RÉGI alakot mindkét formátum elutasítja', function (string $class) {
    // Cél- és verziójelölés nélküli payload: ilyet a rendszer soha nem adott ki, és nem is
    // fogadunk el. A „valahogy értelmezzük" a rosszabb választás: a félig megértett claim
    // alapján JOGALAPOT teremteni súlyosabb, mint nem teremteni.
    $legacy = strtr(
        Crypt::encryptString((string) json_encode([
            'w' => 7, 'e' => 'cimzett@example.test', 'c' => 'marketing',
            'x' => now()->addDay()->getTimestamp(),
        ])),
        '+/=',
        '-_~',
    );

    expect(parseFor($class, $legacy))->toBeNull();
})->with('consent-tokenek');

it('az ISMERETLEN VERZIÓT nem értelmezzük félig', function (string $class) {
    $purpose = $class === ConfirmationTokens::class ? 'optin' : 'prefs';

    $future = strtr(
        Crypt::encryptString((string) json_encode([
            'p' => $purpose, 'v' => 99, 'w' => 7, 'e' => 'cimzett@example.test',
            'c' => 'marketing', 'x' => now()->addDay()->getTimestamp(),
        ])),
        '+/=',
        '-_~',
    );

    expect(parseFor($class, $future))->toBeNull();
})->with('consent-tokenek');
