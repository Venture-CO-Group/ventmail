<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\DevLoginController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
 | A dev-belépés kényelmi eszköz — és pont ezért a legveszélyesebb fajta kód.
 | Két, egymástól független őr védi: a route-regisztráció környezethez kötött, ÉS a
 | kontroller maga is ellenőriz (egy elszabaduló route-cache sem nyithatja meg élesben).
 */

it('teszt-környezetben belép és workspace-kontextust ad', function () {
    $this->get('/auth/dev-login')->assertRedirect();

    expect(Auth::check())->toBeTrue();
    expect(session('workspace_uuid'))->not->toBeEmpty();
});

it('a kontroller éles környezetben dob', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(DevLoginController::class)(request()))
        ->toThrow(RuntimeException::class);
});

it('a route regisztrációja környezethez kötött', function () {
    $source = file_get_contents(app_path('Modules/Identity/routes/web.php'));

    expect($source)->toContain("environment('local', 'testing')");
});

it('a vendéget a ventSSO-belépésre irányítjuk, nem egy nem létező login route-ra', function () {
    // A Laravel alapértelmezése `route('login')` lenne — az nálunk nincs, és 500-at adna
    // minden védett oldalon.
    $this->get('/domains/wizard')->assertRedirect(route('auth.redirect'));
});
