<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSsoProvider;

uses(RefreshDatabase::class);

/*
 | A nyilvános termékoldal (apex) és a belépő (app.) KÜLÖN host-on, ugyanabból az appból.
 | A landing statikus Blade: nincs Inertia, nincs app-JS, és INDEXELHETŐ — az app-oldalak
 | viszont noindex-ek maradnak.
 */

it('az apex host a statikus landinget adja, indexelhetően', function () {
    config(['app.url' => 'https://app.ventmail.app']);

    $response = $this->withHeaders(['Accept-Language' => 'hu'])->get('http://ventmail.app/');

    $response->assertOk()
        // Nem törő kötőjel (U+2011): a cím szélesen sem törhet „e-” / „mail”-ra.
        ->assertSee('A Venture család e‑mail infrastruktúrája')
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

it('az apex hoston az app, az API és az üzemeltetési útvonalak nem érhetők el', function () {
    // A kapu GLOBÁLIS middleware: a /v1, a horizon és a webhook-útvonal sem a web-csoporton át jön.
    $this->postJson('http://ventmail.app/v1/messages', [])->assertNotFound();
    $this->get('http://ventmail.app/horizon')->assertNotFound();
    $this->post('http://ventmail.app/internal/mta/postal/x')->assertNotFound();
    $this->get('http://ventmail.app/auth/redirect')->assertNotFound();
    $this->get('http://ventmail.app/dashboard')->assertNotFound();

    $this->get('http://ventmail.app/')->assertOk();
    $this->get('http://ventmail.app/up')->assertOk();
});

it('az api host / útvonala nem a belépő, hanem 404', function () {
    $this->get('http://api.ventmail.app/')->assertNotFound();
});

it('a landing nem indít munkamenetet és nem állít sütit', function () {
    $this->get('http://ventmail.app/')
        ->assertOk()
        ->assertCookieMissing('XSRF-TOKEN')
        ->assertCookieMissing(config('session.cookie'));
    $this->get('http://ventmail.app/robots.txt')
        ->assertCookieMissing('XSRF-TOKEN')
        ->assertCookieMissing(config('session.cookie'));
});

it('a landing a hostjait a konfigból veszi, és nem hirdet kitalált docs-oldalt', function () {
    config(['app.landing_host' => 'ventmail.app', 'app.url' => 'https://app.ventmail.app']);
    $html = $this->withHeaders(['Accept-Language' => 'hu'])->get('http://ventmail.app/')->getContent();

    expect($html)->toContain('href="https://app.ventmail.app"')
        ->and($html)->not->toContain('api.ventmail.app');
});

it('a helyileg épített glow-utilityk vm- előtagot kapnak', function () {
    $css = file_get_contents(resource_path('js/glow.css'));

    expect($css)->toContain('.vm-glow-rim')->toContain('--vm-rim-angle')
        ->and($css)->not->toContain('.vt-glow');
});

it('a fókusz kényszerített színeknél is látszik (átlátszó körvonal marad)', function () {
    foreach (['css/landing.css', 'js/auth.css', 'js/brand.css', 'js/shell.css'] as $file) {
        expect(file_get_contents(resource_path($file)))->not->toMatch('/outline:\s*none/');
    }
});

it('a robots.txt hostonként más: a landing engedett, az app tiltott', function () {
    $this->app['env'] = 'production';
    config(['proxy.trusted_hosts' => ['ventmail.app', 'app.ventmail.app', 'api.ventmail.app']]);
    $this->get('http://ventmail.app/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Allow: /', false)
        ->assertDontSee('Disallow: /'."\n", false);

    $this->get('http://app.ventmail.app/robots.txt')->assertOk()->assertSee('Disallow: /', false);
    $this->get('http://api.ventmail.app/robots.txt')->assertOk()->assertSee('Disallow: /', false);
});

it('a megtagadott belépés barátságos oldalt ad, 403-mal', function () {
    $sso = new FakeSsoProvider;
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
        ->assertInertia(fn ($page) => $page->component('Auth/Denied'))
        // A belső hibaüzenet (a kivétel szövege) nem kerülhet a felhasználó elé.
        ->assertDontSee('ventSSO-alanyhoz')
        ->assertDontSee('Kérj meghívót');
});

it('a nem szerep-megtagadás (state, token hiba) újrabejelentkezésre visz, Denied oldal nélkül', function () {
    $this->get('/auth/redirect');

    $this->get('/auth/callback?code=auth-code&state=hamisitott')
        ->assertRedirect('/')
        ->assertSessionHas('error');

    $page = $this->followingRedirects()->get('/auth/callback?code=auth-code&state=hamisitott');
    $page->assertInertia(fn ($p) => $p->component('Auth/Login'))
        ->assertDontSee('CSRF')
        ->assertDontSee('OIDC state');
});

it('az apex-kaput a továbbított host is érvényesíti, de csak trusted proxyról', function () {
    config(['proxy.trusted_proxies' => ['127.0.0.1']]);
    $this->get('http://app.ventmail.app/dashboard', ['X-Forwarded-Host' => 'ventmail.app'])->assertNotFound();

    config(['proxy.trusted_proxies' => []]);
    Illuminate\Http\Request::setTrustedProxies([], -1);
    $this->get('http://app.ventmail.app/dashboard', ['X-Forwarded-Host' => 'ventmail.app'])->assertRedirect();
});

it('a robots.txt csak production-ben engedi az indexelést', function () {
    $this->app['env'] = 'production';
    config(['proxy.trusted_hosts' => ['ventmail.app', 'app.ventmail.app', 'api.ventmail.app']]);
    $this->get('http://ventmail.app/robots.txt')->assertSee('Allow: /', false);

    $this->app['env'] = 'testing';
    $this->get('http://ventmail.app/robots.txt')->assertSee('Disallow: /', false)->assertDontSee('Allow: /', false);
});

it('az app.version a konfigból jön, alapértéke dev', function () {
    expect(config('app.version'))->toBe('dev');
});
