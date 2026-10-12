<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;

/*
 | A Laravel `api` csoportja alapból CSAK SubstituteBindings — se throttle, se limiter.
 | Egy ESP API-felülete rate-limit nélkül nem indulhat (golden rule 2, 5. kapu).
 */

it('az api limiter definiálva van', function () {
    expect(RateLimiter::limiter('api'))->not->toBeNull('nincs `api` rate limiter — a throttle:api elszállna');
});

it('a modul-API stack tartalmazza a throttle-t', function () {
    $source = file_get_contents(app_path('Modules/Support/ModuleServiceProvider.php'));

    expect($source)->toContain("'throttle:api'");
});

it('a proxy-fejlécek megbízhatók a Caddy mögött', function () {
    $source = file_get_contents(base_path('bootstrap/app.php'));

    // A bizalmi határ a `TrustEdgeProxies` (config/proxy.php): a viselkedést az
    // EdgeProxyTest méri (edge-től igen, bárki mástól nem, prefix/port soha).
    expect($source)->toContain('TrustEdgeProxies::class');
});

it('a titkok nem kerülnek exception-kontextusba', function () {
    $source = file_get_contents(base_path('bootstrap/app.php'));

    expect($source)
        ->toContain('dontFlash')
        ->toContain('client_secret')
        ->toContain('dkim_private_key');
});
