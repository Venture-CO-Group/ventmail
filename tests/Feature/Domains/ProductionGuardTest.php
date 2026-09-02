<?php

declare(strict_types=1);

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Services\SystemDnsResolver;
use App\Modules\Domains\Testing\FakeDnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | A hitelesítés BIZALMI ORÁKULUMA nem lehet fake élesben: a FakeDnsResolver publikus
 | `publish()` metódusa azt jelentené, hogy a domain-tulajdon bizonyítása egy in-memory
 | tömb, amit alkalmazás-kódból bárki felülírhat (golden rule 8 megkerülése).
 */

it('a resolver configból választható', function () {
    config(['domains.resolver' => 'fake']);
    app()->forgetInstance(DnsResolver::class);

    expect(app(DnsResolver::class))->toBeInstanceOf(FakeDnsResolver::class);

    config(['domains.resolver' => 'system']);
    app()->forgetInstance(DnsResolver::class);

    expect(app(DnsResolver::class))->toBeInstanceOf(SystemDnsResolver::class);
});

it('éles környezetben a fake resolver hangosan elszáll', function () {
    $guard = file_get_contents(app_path('Modules/Domains/Providers/DomainsServiceProvider.php'));

    expect($guard)->toContain('isProduction');
});

it('a dev-belépés NEM csak az APP_ENV-en múlik', function () {
    // Az `.env.example` alapértéke `local` — aki a sablonból indul egy szerveren, alapból
    // ebben az állapotban lenne. Ezért kell FÜGGETLEN második kapcsoló.
    expect(config('identity.dev_login_enabled'))->toBeBool();

    config(['identity.dev_login_enabled' => false]);

    $this->get('/auth/dev-login')->assertNotFound();
});

it('a dev-belépés nem irányít külső címre', function () {
    config(['identity.dev_login_enabled' => true]);

    $response = $this->get('/auth/dev-login?to=https://tamado.example/atveres');

    expect($response->headers->get('Location'))->not->toContain('tamado.example');
});

it('a dev-belépés a nem létező route helyett 404-et ad', function () {
    config(['identity.dev_login_enabled' => false]);

    $this->get('/auth/dev-login')->assertNotFound();
});
