<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 | A FÉKEK KULCSA — nem a kérő szava.
 |
 | A `TRUSTED_PROXIES` alapértéke `*`, tehát a `request->ip()` a KÉRŐ által beírt
 | `X-Forwarded-For` fejlécből jön. Mérve: `REMOTE_ADDR=172.18.0.9` +
 | `X-Forwarded-For: 9.9.9.9` mellett a `request->ip()` `9.9.9.9`. Naplózáshoz és földrajzi
 | jelzéshez ez rendben (ott a proxy adja a helyes értéket), egy RATE-LIMIT KULCSÁHOZ viszont
 | nem: aki a fejlécet írja, magának ad friss keretet.
 |
 | Ez konkrét károkat okozott: az egyetlen fék a publikus, visszavonhatatlan
 | suppression-listára író `/u/` végponton rotáló XFF-fel kikapcsolható volt, és az
 | API-kulcs brute-force plafonja ugyanígy. A helyes hosszú távú javítás a `TRUSTED_PROXIES`
 | leszűkítése (devops) — de amíg az nincs meg, a fékeknek a VALÓDI TCP-partnerre kell
 | kulcsolniuk.
 |
 | Ez a teszt őrszem: ha valaki új limitert ír `request->ip()`-vel, itt bukik el.
 */

it('egyetlen rate-limiter sem kulcsol a `request->ip()`-re', function () {
    $source = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

    // A limiter-definíciók blokkja: az `ip()` hívás ott a kérő szava.
    $limiters = substr($source, (int) strpos($source, 'configureRateLimiting'));

    expect($limiters)->not->toContain('$request->ip()');
    // …és a helyettesítő ott van: a valódi TCP-partner.
    expect($limiters)->toContain('self::peer($request)');
});

it('a `peer()` a ClientAddress-t adja: TCP-partner, vagy CSAK a szűkített edge-től a továbbított cím', function () {
    $provider = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));
    $client = (string) file_get_contents(app_path('Support/ClientAddress.php'));

    expect($provider)->toContain('ClientAddress::of($request)');
    expect($client)->toContain("server('REMOTE_ADDR')");
    // A továbbított címet csak a megbízható proxytól veszi át — a `*` bizalom mellett sem.
    expect($client)->toContain('isFromTrustedProxy()');
    expect($client)->toContain("=== ['*']");
});

it('az API-kulcs brute-force plafonja sem a kérő szavára kulcsol', function () {
    // A `credentialKey` kulcsforgatásnál nem fékez, tehát az IP-vödör az egyetlen valódi
    // plafon — annak pedig nem lehet átírható a kulcsa.
    $source = (string) file_get_contents(
        app_path('Modules/Api/Http/Middleware/AuthenticateApiKey.php')
    );

    expect($source)->toContain('sourceBucket(ClientAddress::of($request))');
    expect($source)->not->toContain('sourceBucket((string) $request->ip())');
});

it('a publikus végpontok fékjei nem GLOBÁLIS vödrök', function () {
    // A `REMOTE_ADDR` egy proxy mögött MINDEN kérésre ugyanaz: egy önálló partner-vödör
    // ezért nem védelem, hanem globális kill switch — aki kimeríti, mindenki más kérését
    // 429-re állítja. Mérve, egy korábbi változaton: 3100 kérés után minden VALÓDI Gmail
    // one-click POST 429-et kapott.
    //
    // Ezért az érvényes igény fékje az IGÉNYRE kulcsol, az `mta-sts` pedig hoszttal
    // PÁROSÍTVA — egyik sem tud egyetlen vödörbe összefolyni.
    $source = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

    expect($source)->toContain("'claim:'.\$request->method()");
    expect($source)->toContain("'peer-host:'");
});

it('minden `throttle:` middleware-hez tartozik definiált limiter', function () {
    // Egy elírt limiter-név (`throttle:unsubscribes`) a Laravelben KIVÉTELT dob a kérésben —
    // publikus végponton ez 500, és a fék helyén semmi.
    $defined = [];

    preg_match_all(
        "/RateLimiter::for\('([a-z0-9\-]+)'/",
        (string) file_get_contents(app_path('Providers/AppServiceProvider.php')),
        $matches
    );

    $defined = $matches[1] ?? [];

    $used = [];

    foreach (File::allFiles(app_path('Modules')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all(
            "/throttle:([a-z0-9\-]+)/",
            (string) file_get_contents($file->getPathname()),
            $found
        );

        $used = array_merge($used, $found[1] ?? []);
    }

    $undefined = array_values(array_unique(array_diff($used, $defined)));

    expect($used)->not->toBeEmpty('nem találtunk egyetlen throttle-nevet sem — a minta elromlott');
    expect($undefined)->toBeEmpty('definiálatlan rate-limiter: '.implode(', ', $undefined));
});
