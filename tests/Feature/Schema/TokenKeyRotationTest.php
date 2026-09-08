<?php

declare(strict_types=1);

use App\Modules\Events\Services\TrackingTokens;
use App\Modules\Suppression\Services\UnsubscribeTokens;
use Illuminate\Support\Facades\Crypt;

/*
 | KULCSROTÁCIÓ — a kiküldött levelekben lévő linkek élete.
 |
 | A `/u/{token}` és a `/t/{token}` linkek az `APP_KEY`-jel titkosított tokent hordoznak, és
 | a levél HÓNAPOKIG él a címzett postafiókjában. Egy security-review joggal kérdezte, hogy
 | hol a kulcs-azonosító (`kid`) és az átfedéses ellenőrzés: nélkülük egy kulcscsere
 | EGYSZERRE némítaná el minden korábbi levél leiratkozó linkjét — és NÉMÁN, mert a `/u/`
 | szándékosan nem ad orákulumot (a címzett a „leiratkoztál" oldalt látja, miközben semmi nem
 | íródik → GDPR 21. sérül).
 |
 | A válasz: `kid` nem kell, mert a Laravel `Crypt` a `previous_keys` listát VÉGIGPRÓBÁLJA. A
 | kockázat tehát nem kriptográfiai, hanem ÜZEMELTETÉSI — és ez a teszt azt rögzíti, hogy a
 | mechanizmus valóban működik, illetve hogy a hiánya valóban öl.
 */

function rotateTo(string $key, array $previous = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previous]);

    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstances();
}

it('a RÉGI leiratkozó token megfejtődik a `previous_keys` listával', function () {
    $old = 'base64:'.base64_encode(random_bytes(32));
    $new = 'base64:'.base64_encode(random_bytes(32));

    rotateTo($old);

    $token = app(UnsubscribeTokens::class)->issue(7, 'aaaa-bbbb', 'anna@example.test');

    // ROTÁCIÓ: új kulcs, a régi a `previous_keys`-ben.
    rotateTo($new, [$old]);

    expect(app(UnsubscribeTokens::class)->parse($token))->toBe([
        'workspaceId' => 7,
        'messageUuid' => 'aaaa-bbbb',
        'recipient' => 'anna@example.test',
    ]);
});

it('a RÉGI kulcs ELDOBÁSA elnémítja a kiküldött levelek leiratkozó linkjét', function () {
    // Ez a teszt a KÁRT rögzíti, nem a védelmet: ha valaki a rotációt a régi kulcs
    // megőrzése nélkül végzi el, ez történik. Az `.env.example` ezért írja le a menetét.
    $old = 'base64:'.base64_encode(random_bytes(32));
    $new = 'base64:'.base64_encode(random_bytes(32));

    rotateTo($old);

    $token = app(UnsubscribeTokens::class)->issue(7, 'aaaa-bbbb', 'anna@example.test');

    rotateTo($new);

    expect(app(UnsubscribeTokens::class)->parse($token))->toBeNull();
});

it('a NYOMKÖVETÉSI token is túléli a rotációt', function () {
    $old = 'base64:'.base64_encode(random_bytes(32));
    $new = 'base64:'.base64_encode(random_bytes(32));

    rotateTo($old);

    $token = app(TrackingTokens::class)
        ->issue(7, 'aaaa-bbbb', 'anna@example.test', 'email.pelda.hu', 'https://cel.example/x');

    rotateTo($new, [$old]);

    $claim = app(TrackingTokens::class)->parse($token);

    expect($claim)->not->toBeNull();
    expect($claim->recipient)->toBe('anna@example.test');
    expect($claim->destination)->toBe('https://cel.example/x');
});

it('a `previous_keys` az env-ből épül, vesszővel elválasztva', function () {
    // A rotációs eljárás egy env-változón múlik: ha a config nem olvassa, a fenti védelem
    // papíron létezik. A `.env.example` dokumentálja is.
    expect(file_get_contents(config_path('app.php')))->toContain('APP_PREVIOUS_KEYS');
    expect(file_get_contents(base_path('.env.example')))->toContain('APP_PREVIOUS_KEYS');
});
