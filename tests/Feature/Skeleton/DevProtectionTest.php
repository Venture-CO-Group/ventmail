<?php

declare(strict_types=1);

/*
 | Golden rule 11: dev = noindex + Basic Auth. A reviewer jogos észrevétele, hogy erre
 | nem volt regressziós védelem — ha valaki kiveszi a basic_auth blokkot, a CI zöld maradt.
 */

it('a Caddyfile kikényszeríti a noindex fejlécet', function () {
    $caddyfile = file_get_contents(base_path('docker/Caddyfile.dev'));

    expect($caddyfile)->toContain('X-Robots-Tag')->toContain('noindex');
});

it('a Caddyfile Basic Authot követel a dev-en', function () {
    $caddyfile = file_get_contents(base_path('docker/Caddyfile.dev'));

    expect($caddyfile)->toContain('basic_auth');
});

it('a robots.txt mindent tilt', function () {
    $robots = file_get_contents(public_path('robots.txt'));

    expect($robots)->toContain('Disallow: /');
});

it('a Caddy dev hitelesítője friss klónon is használható', function () {
    // Üres hash mellett a Caddy „username and password cannot be empty" parse-errorral
    // loopolna, és az első reflex a basic_auth kikommentelése lenne (golden rule 11 elvész).
    // A hash külön fájlban van, mert a compose a gyökér .env-et interpolálja.
    // A compose ezt a fájlt is interpolálja, ezért a bcrypt $-ok duplázva vannak ($$).
    $caddyEnv = file_get_contents(base_path('docker/caddy.dev.env'));

    expect($caddyEnv)->toMatch('/DEV_BASIC_AUTH_HASH=\$\$2[aby]\$\$/');
});
