<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | A nyilvános termékoldal (apex) és a belépő (app.) KÜLÖN host-on, ugyanabból az appból.
 | A landing statikus Blade: nincs Inertia, nincs app-JS, és INDEXELHETŐ — az app-oldalak
 | viszont noindex-ek maradnak.
 */

it('az apex host a statikus landinget adja, indexelhetően', function () {
    $response = $this->withHeaders(['Accept-Language' => 'hu'])->get('http://ventmail.app/');

    $response->assertOk()
        ->assertSee('A Venture család e-mail infrastruktúrája')
        ->assertSee('<link rel="canonical" href="https://ventmail.app/">', false)
        ->assertSee('property="og:title"', false)
        ->assertSee('https://app.ventmail.app', false)
        ->assertDontSee('noindex')
        ->assertDontSee('data-page=', false);
});

it('a landing a termékállítások mellé nem gyárt árat vagy ügyfelet', function () {
    $html = $this->withHeaders(['Accept-Language' => 'hu'])->get('http://ventmail.app/')->getContent();

    expect($html)->toContain('SPF')->toContain('RFC 8058')
        ->and($html)->not->toMatch('/\d+\s*(Ft|EUR|€)/u');
});

it('az app host a belépő oldalt adja vendégnek, noindex-szel', function () {
    $this->get('http://app.ventmail.app/')
        ->assertOk()
        ->assertSee('noindex, nofollow', false)
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->has('app.version'));
});

it('a bejelentkezett felhasználót a /-ről a dashboardra viszi', function () {
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'login-'.uniqid(),
        'name' => 'Teszt',
        'email' => 'login-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);

    $this->actingAs($user)->get('http://app.ventmail.app/')->assertRedirect('/dashboard');
});

it('az apex hoston az app útvonalai nem érhetők el', function () {
    $this->get('http://ventmail.app/auth/redirect')->assertNotFound();
    $this->get('http://ventmail.app/dashboard')->assertNotFound();
});

it('a robots.txt hostonként más: a landing engedett, az app tiltott', function () {
    $this->get('http://ventmail.app/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Allow: /', false)
        ->assertDontSee('Disallow: /'."\n", false);

    $this->get('http://app.ventmail.app/robots.txt')->assertOk()->assertSee('Disallow: /', false);
    $this->get('http://api.ventmail.app/robots.txt')->assertOk()->assertSee('Disallow: /', false);
});

it('a megtagadott belépés barátságos oldalt ad, 403-mal', function () {
    $sso = new Tests\Support\FakeSsoProvider;
    config([
        'oidc.provider_url' => $sso->issuer,
        'oidc.client_id' => 'ventmail',
        'oidc.client_secret' => 'test-secret',
        'oidc.roles_claim' => 'resource_access.ventmail.roles',
    ]);
    $sso->fakeHttp();

    $this->get('/auth/redirect');

    // Érvényes token, de ventMail-szerep nélkül és tagság/meghívó nélkül.
    $sso->fakeHttp(['id_token' => $sso->idToken(['aud' => 'ventmail', 'nonce' => session('oidc.nonce')])]);

    $this->get('/auth/callback?code=auth-code&state='.session('oidc.state'))
        ->assertStatus(403)
        ->assertInertia(fn ($page) => $page->component('Auth/Denied'));
});

it('az app.version a konfigból jön, alapértéke dev', function () {
    expect(config('app.version'))->toBe('dev');
});
