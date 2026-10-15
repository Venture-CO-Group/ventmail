<?php

declare(strict_types=1);

/*
 | ventCookie consent loader a ventMail SAJÁT publikus oldalain (apex landing). A consent
 | kizárólag a ventCookie-é: itt csak a betöltő snippet él. Nincs DB-igény.
 */

function landingHtml(): string
{
    return test()->withHeaders(['Accept-Language' => 'en'])->get('http://ventmail.app/')->assertOk()->getContent();
}

it('a loader az első script a head-ben, integrity-vel, async/defer nélkül', function () {
    config([
        'ventcookie.domain_token' => 'TOKEN123',
        'ventcookie.loader_src' => 'https://cookie.ventureco.app/cdn/v1/loader.js',
        'ventcookie.loader_sri' => 'sha384-abc',
    ]);

    $html = landingHtml();
    $head = substr($html, (int) strpos($html, '<head>'), (int) strpos($html, '</head>') - (int) strpos($html, '<head>'));

    expect($html)->toContain('<script src="https://cookie.ventureco.app/cdn/v1/loader.js" data-vc-token="TOKEN123" integrity="sha384-abc" crossorigin="anonymous"></script>')
        ->and(substr_count($head, '<script'))->toBe(1)
        ->and(strpos($head, '<script'))->toBeLessThan((int) strpos($head, '<meta charset'))
        ->and($head)->not->toContain('async')->not->toContain('defer');
});

it('üres tokennel semmit nem renderel a loaderből', function () {
    config(['ventcookie.domain_token' => '', 'ventcookie.loader_sri' => 'sha384-abc']);

    expect(landingHtml())->not->toContain('data-vc-token')->not->toContain('loader.js');
});

it('integrity nélkül is betölt, de integrity attribútum nélkül', function () {
    config(['ventcookie.domain_token' => 'T', 'ventcookie.loader_sri' => '']);

    expect(landingHtml())->toContain('data-vc-token="T"')->not->toContain('integrity=');
});

it('a verifikációs meta csak beállított értékkel jelenik meg', function () {
    config(['ventcookie.site_verification' => 'ventcookie-site-verification=abc']);
    expect(landingHtml())->toContain('<meta name="ventcookie-verification" content="ventcookie-site-verification=abc">');

    config(['ventcookie.site_verification' => '']);
    expect(landingHtml())->not->toContain('ventcookie-verification');
});

it('a CSP script-src és connect-src engedi a cookie.ventureco.app-ot', function () {
    $csp = (string) file_get_contents(base_path('docker/caddy/snippets/security-headers.caddy'));

    expect($csp)->toMatch('/script-src [^;"]*https:\/\/cookie\.ventureco\.app/')
        ->and($csp)->toMatch('/connect-src [^;"]*https:\/\/cookie\.ventureco\.app/');
});

it('a láblécben süti-beállítások link nyitja a ventCookie preferenciákat', function () {
    config(['ventcookie.domain_token' => 'T']);

    landingHtml();
    expect(landingHtml())
        ->toContain('data-vc-open-preferences')
        ->toContain('Cookie settings');
});

it('a süti-beállítások link minden nyelven fordított', function () {
    foreach (['hu', 'en', 'de', 'fr', 'it', 'es', 'ru'] as $locale) {
        expect((string) trans('landing.footer.cookies', [], $locale))->not->toBe('landing.footer.cookies');
    }
});

it('token nélkül nincs süti-beállítások link (nincs halott gomb)', function () {
    config(['ventcookie.domain_token' => '']);

    expect(landingHtml())->not->toContain('data-vc-open-preferences');
});
