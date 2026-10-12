<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Actions\SyncSsoUser;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
 | v1.1 · SHELL · D11 — a nyelv FORRÁSA.
 |
 | Az első belépéskor a ventSSO `locale` claimje dönt (`locale_source = sso`); amint a
 | felhasználó maga választ (`user`), az SSO többé nem írja felül. Enélkül a nyelvválasztó
 | minden bejelentkezésnél visszaugrana arra, amit a ventSSO-ban beállítottak.
 */

function ssoClaims(string $sub, mixed $locale): array
{
    return [
        'iss' => 'https://sso.teszt.invalid',
        'sub' => $sub,
        'email' => $sub.'@pelda.hu',
        'email_verified' => true,
        'name' => 'Teszt',
        'locale' => $locale,
    ];
}

it('az első belépés a ventSSO claim nyelvét veszi át, és sso-ként jelöli', function () {
    $user = app(SyncSsoUser::class)(ssoClaims('uj-1', 'fr-CA'));

    expect($user->locale)->toBe('fr')
        ->and($user->locale_source)->toBe('sso');
});

it('a következő belépés frissíti az sso-forrású nyelvet', function () {
    app(SyncSsoUser::class)(ssoClaims('valt-1', 'de'));
    $user = app(SyncSsoUser::class)(ssoClaims('valt-1', 'it'));

    expect($user->locale)->toBe('it')->and($user->locale_source)->toBe('sso');
});

it('a felhasználó saját választását az SSO claim NEM írja felül', function () {
    $user = app(SyncSsoUser::class)(ssoClaims('sajat-1', 'de'));
    $user->forceFill(['locale' => 'es', 'locale_source' => 'user'])->save();

    $again = app(SyncSsoUser::class)(ssoClaims('sajat-1', 'ru'));

    expect($again->locale)->toBe('es')->and($again->locale_source)->toBe('user');
});

it('nem támogatott claimnél az alapnyelv marad, és nincs sso-forrás', function () {
    $user = app(SyncSsoUser::class)(ssoClaims('zz-1', 'zz'));

    expect($user->locale)->toBe((string) config('app.locale'))
        ->and($user->locale_source)->toBeNull();
});

it('a nyelvválasztó user-forrásúra állítja a nyelvet', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['locale' => 'hu', 'locale_source' => 'sso']);
    $workspace->members()->create(['user_id' => $user->id, 'roles' => ['owner']]);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->post('/locale', ['locale' => 'fr'])
        ->assertRedirect();

    expect($user->fresh()->locale)->toBe('fr')->and($user->fresh()->locale_source)->toBe('user');
});

it('a francia támogatott a nyelvválasztóban', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'roles' => ['owner']]);

    $this->withoutVite()->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('supportedLocales', fn ($list) => collect($list)->contains('fr')));
});

describe('SetLocale sorrend', function () {
    beforeEach(function () {
        Route::middleware(['web', 'auth'])->get('/teszt/nyelv-forras', fn () => ['locale' => app()->getLocale()]);
    });

    it('a user-választás erősebb az Accept-Languagenél', function () {
        $user = User::factory()->create(['locale' => 'de', 'locale_source' => 'user']);

        $this->actingAs($user)->withHeaders(['Accept-Language' => 'it'])->get('/teszt/nyelv-forras')
            ->assertJson(['locale' => 'de']);
    });

    it('az sso claim nyelve erősebb az Accept-Languagenél', function () {
        $user = User::factory()->create(['locale' => 'fr', 'locale_source' => 'sso']);

        $this->actingAs($user)->withHeaders(['Accept-Language' => 'it'])->get('/teszt/nyelv-forras')
            ->assertJson(['locale' => 'fr']);
    });

    it('választás nélkül (nincs forrás) az Accept-Language dönt, nem a tábla alapértéke', function () {
        // A `users.locale` alapértéke `hu` — ha ez nyerne, a böngésző nyelve soha nem érvényesülne.
        $user = User::factory()->create(['locale' => 'hu', 'locale_source' => null]);

        $this->actingAs($user)->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9'])->get('/teszt/nyelv-forras')
            ->assertJson(['locale' => 'fr']);
    });

    it('forrás nélkül és támogatott fejléc híján a tárolt nyelv marad (nem a lista első eleme)', function () {
        $user = User::factory()->create(['locale' => 'es', 'locale_source' => null]);

        $this->actingAs($user)->withHeaders(['Accept-Language' => 'zh-CN,zh;q=0.9'])->get('/teszt/nyelv-forras')
            ->assertJson(['locale' => 'es']);
    });
});
