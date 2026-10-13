<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | v1.1 · SHELL · /profile — a felhasználó APP-SPECIFIKUS beállításai.
 |
 | A személyes adat, a jelszó és a 2FA a ventSSO-ban él: ezt az oldal nem duplikálja, csak
 | linkel. A teszt azt is méri, hogy az oldal NEM fogad el ilyen mezőt.
 */

function profileUser(array $attributes = []): array
{
    $workspace = Workspace::factory()->create(['name' => 'Alfa']);
    $user = User::factory()->create($attributes);
    $workspace->members()->create(['user_id' => $user->id, 'roles' => ['owner']]);

    return [$user, $workspace];
}

it('a profiloldal a beállításokat és az sso-fiók linkjét adja', function () {
    config(['identity.sso_account_url' => 'https://sso.pelda.invalid/account']);
    [$user, $workspace] = profileUser(['locale' => 'de', 'locale_source' => 'user', 'timezone' => 'Europe/Berlin']);

    $this->withoutVite()->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Identity/Profile')
            ->where('settings.locale', 'de')
            ->where('settings.timezone', 'Europe/Berlin')
            ->where('settings.notifications.import_finished', true)
            ->where('settings.notifications.weekly_summary', false)
            ->where('ssoAccountUrl', 'https://sso.pelda.invalid/account')
            ->has('timezones')
            ->has('workspaces', 1));
});

it('a beállítások mentése validál és ír', function () {
    [$user, $workspace] = profileUser(['locale' => 'hu', 'locale_source' => 'sso']);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->put('/profile', [
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'dmy',
            'number_format' => 'space_comma',
            'notifications' => [
                'import_finished' => false,
                'report_ready' => true,
                'deliverability_alerts' => true,
                'weekly_summary' => true,
            ],
            'default_workspace' => $workspace->uuid,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $fresh = $user->fresh();

    expect($fresh->locale)->toBe('fr')
        ->and($fresh->locale_source)->toBe('user')
        ->and($fresh->timezone)->toBe('Europe/Paris')
        ->and($fresh->date_format)->toBe('dmy')
        ->and($fresh->number_format)->toBe('space_comma')
        ->and($fresh->notification_prefs)->toEqual([
            'import_finished' => false,
            'report_ready' => true,
            'deliverability_alerts' => true,
            'weekly_summary' => true,
        ])
        ->and($fresh->default_workspace_id)->toBe($workspace->id);
});

it('a profil mentése (akár változatlan nyelvvel) user-választásként rögzíti a nyelvet', function () {
    [$user, $workspace] = profileUser(['locale' => 'hu', 'locale_source' => 'sso']);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->put('/profile', ['locale' => 'hu', 'timezone' => 'Europe/Budapest'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->locale_source)->toBe('user');
});

it('érvénytelen értékeket elutasít', function (array $payload, string $field) {
    [$user, $workspace] = profileUser();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->put('/profile', $payload)
        ->assertSessionHasErrors($field);
})->with([
    'nyelv' => [['locale' => 'zz'], 'locale'],
    'időzóna' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'dátumformátum' => [['date_format' => 'xx'], 'date_format'],
    'számformátum' => [['number_format' => 'xx'], 'number_format'],
    'értesítés nem logikai' => [['notifications' => ['weekly_summary' => 'talan']], 'notifications.weekly_summary'],
]);

it('alapértelmezett workspace-nek csak saját tagság adható', function () {
    [$user, $workspace] = profileUser();
    $foreign = Workspace::factory()->create();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->put('/profile', ['default_workspace' => $foreign->uuid])
        ->assertSessionHasErrors('default_workspace');

    expect($user->fresh()->default_workspace_id)->toBeNull();
});

it('az alapértelmezett workspace törölhető (üres érték)', function () {
    [$user, $workspace] = profileUser();
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->put('/profile', ['default_workspace' => null])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->default_workspace_id)->toBeNull();
});

it('személyes adatot (név, e-mail, jelszó) NEM fogad el — az a ventSSO-é', function () {
    [$user, $workspace] = profileUser(['name' => 'Eredeti', 'email' => 'eredeti@pelda.hu']);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->put('/profile', ['name' => 'Hamis', 'email' => 'hamis@pelda.hu', 'password' => 'x', 'platform_admin' => true])
        ->assertSessionHasNoErrors();

    $fresh = $user->fresh();

    expect($fresh->name)->toBe('Eredeti')
        ->and($fresh->email)->toBe('eredeti@pelda.hu')
        ->and($fresh->isPlatformAdmin())->toBeFalse();
});

it('bejelentkezés nélkül nem érhető el', function () {
    $this->put('/profile', ['locale' => 'hu'])->assertRedirect();
    $this->get('/profile')->assertRedirect();
});

it('session-választás híján az alapértelmezett workspace-t oldja fel', function () {
    $first = Workspace::factory()->create(['name' => 'Elso']);
    $second = Workspace::factory()->create(['name' => 'Masodik']);
    $user = User::factory()->create();
    $first->members()->create(['user_id' => $user->id, 'roles' => ['owner']]);
    $second->members()->create(['user_id' => $user->id, 'roles' => ['admin']]);
    $user->forceFill(['default_workspace_id' => $second->id])->save();

    $this->withoutVite()->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('workspace.uuid', (string) $second->uuid));

    // A session-választás erősebb az alapértelmezettnél.
    $this->withoutVite()->actingAs($user)->withSession(['workspace_uuid' => $first->uuid])->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('workspace.uuid', (string) $first->uuid));
});

it('az alapértelmezett workspace elvesztett tagság után nem ad hozzáférést', function () {
    $mine = Workspace::factory()->create();
    $gone = Workspace::factory()->create();
    $user = User::factory()->create();
    $mine->members()->create(['user_id' => $user->id, 'roles' => ['owner']]);
    $user->forceFill(['default_workspace_id' => $gone->id])->save();

    $this->withoutVite()->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('workspace.uuid', (string) $mine->uuid));
});

it('a héj megosztott props-a tartalmazza az sso-linket és a verziót', function () {
    config(['app.version' => 'v1.2.0']);
    [$user, $workspace] = profileUser();

    $this->withoutVite()->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('app.version', 'v1.2.0')
            ->where('ssoAccountUrl', config('identity.sso_account_url'))
            ->where('workspaces.0.roles.0', 'owner'));
});

it('az sso-fiók URL-je konfigurálható az OIDC-szolgáltatóból, alapértéke a Venture ssó', function () {
    $default = configFileWith('identity', ['SSO_ACCOUNT_URL' => null, 'OIDC_PROVIDER_URL' => null]);
    $fromIssuer = configFileWith('identity', ['SSO_ACCOUNT_URL' => null, 'OIDC_PROVIDER_URL' => 'https://sso.pelda.invalid/']);
    $explicit = configFileWith('identity', ['SSO_ACCOUNT_URL' => 'https://acc.pelda.invalid/me', 'OIDC_PROVIDER_URL' => 'https://x.invalid']);

    expect($default['sso_account_url'])->toBe('https://sso.ventureco.app/account')
        ->and($fromIssuer['sso_account_url'])->toBe('https://sso.pelda.invalid/account')
        ->and($explicit['sso_account_url'])->toBe('https://acc.pelda.invalid/me');
});

it('a mentett értesítések visszatöltődnek (round-trip), és a következő mentés nem írja felül', function () {
    [$user, $workspace] = profileUser();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->put('/profile', ['notifications' => ['weekly_summary' => true]])->assertSessionHasNoErrors();

    $this->withoutVite()->actingAs($user->fresh())->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/profile')
        ->assertInertia(fn ($page) => $page->where('settings.notifications.weekly_summary', true));

    // Másik kulcs mentése nem állítja vissza a korábbit.
    $this->actingAs($user->fresh())->withSession(['workspace_uuid' => $workspace->uuid])
        ->put('/profile', ['notifications' => ['report_ready' => false]])->assertSessionHasNoErrors();

    expect($user->fresh()->notification_prefs['weekly_summary'])->toBeTrue()
        ->and($user->fresh()->notification_prefs['report_ready'])->toBeFalse();
});

it('a profil az EFFEKTÍV nyelvet mutatja, és forrás nélkül az azonos mentés is user-választás', function () {
    [$user, $workspace] = profileUser(['locale' => 'hu', 'locale_source' => null]);

    $this->withoutVite()->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->withHeaders(['Accept-Language' => 'fr-FR'])
        ->get('/profile')
        ->assertInertia(fn ($page) => $page->where('settings.locale', 'fr'));

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->withHeaders(['Accept-Language' => 'fr-FR'])
        ->put('/profile', ['locale' => 'fr'])->assertSessionHasNoErrors();

    expect($user->fresh()->locale)->toBe('fr')->and($user->fresh()->locale_source)->toBe('user');
});

it('a profilmódosítás auditált, a megváltozott kulcsokkal és érték nélkül', function () {
    [$user, $workspace] = profileUser();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->put('/profile', ['timezone' => 'Europe/Paris', 'date_format' => 'iso'])->assertSessionHasNoErrors();

    $log = AuditLog::query()->where('action', 'user.settings.updated')->firstOrFail();

    expect($log->context['changed'])->toEqualCanonicalizing(['timezone', 'date_format'])
        ->and(json_encode($log->context))->not->toContain('Paris');
});

it('az értesítések tömb mérete korlátos', function () {
    [$user, $workspace] = profileUser();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->put('/profile', ['notifications' => array_fill_keys(range(1, 50), true)])
        ->assertSessionHasErrors('notifications');
});

it('a héj megosztott `format` propja a profil EFFEKTÍV formázási beállításait adja (D11 / D284)', function () {
    // A felület MINDEN szám- és dátumkiírása ebből formáz (`useFormat`), nem a böngésző nyelvéből.
    [$user, $workspace] = profileUser(['timezone' => 'Europe/Paris', 'date_format' => 'iso', 'number_format' => 'comma_dot']);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/profile')
        ->assertInertia(fn ($page) => $page
            ->where('format.timezone', 'Europe/Paris')
            ->where('format.dateFormat', 'iso')
            ->where('format.numberFormat', 'comma_dot'));
});

it('beállítás híján a `format` prop a kanonikus alapértékeket adja', function () {
    config(['app.timezone' => 'UTC']);
    [$user, $workspace] = profileUser(['timezone' => null, 'date_format' => null, 'number_format' => null]);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/profile')
        ->assertInertia(fn ($page) => $page
            ->where('format.timezone', 'UTC')
            ->where('format.dateFormat', 'ymd')
            ->where('format.numberFormat', 'space_comma'));
});
