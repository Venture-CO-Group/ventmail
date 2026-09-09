<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\SuperAdmin\Models\PlanOverride;
use App\Modules\SuperAdmin\Services\Impersonation;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | M4-5 · A PLATFORM-KONZOL FELÜLETE — a C2 backendjének a hiányzó fele.
 |
 | A C2 lezárásakor kimondtuk: a konzol felülete önálló fázis, és amikor megjön, az egész
 | C2-t újra kell nézni, mert a jogosultsági megállapítások addig szolgáltatás-szintűek
 | voltak. Ez a teszt-fájl épp azt méri, ami HTTP-szinten új: hogy a képernyők
 | `platform.admin`-hoz kötöttek, és hogy a támogatói nézet írás-tilalma a felületen is áll.
 */

function consoleAdmin(): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'console-'.uniqid(),
        'name' => 'Platform Admin',
        'email' => 'console-'.uniqid().'@ventmail.app',
    ]);

    $user->forceFill(['platform_admin' => true])->save();

    return $user;
}

function consoleTenant(Workspace $workspace): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'tenant-'.uniqid(),
        'name' => 'Tenant',
        'email' => 'tenant-'.uniqid().'@pelda.hu',
    ]);

    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => ['owner']]);

    return $user;
}

it('a konzolt TENANT-FELHASZNÁLÓ nem nyithatja meg — sem a képernyőt, sem a keresőt', function () {
    $workspace = Workspace::factory()->create();
    $owner = consoleTenant($workspace);

    $this->actingAs($owner)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/platform')
        ->assertForbidden();

    $this->actingAs($owner)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/platform/audit')
        ->assertForbidden();
});

it('a konzol a cluster-állapotot és a globális tiltásokat mutatja', function () {
    $workspace = Workspace::factory()->create();
    $admin = consoleAdmin();
    $workspace->members()->create(['user_id' => $admin->getKey(), 'roles' => ['owner']]);

    Suppression::onPlatform(fn () => Suppression::query()->create([
        'email' => 'globalis@spamtrap.example',
        'applies_to' => 'all',
        'reason' => 'policy',
        'source' => 'platform_admin',
        'detail' => 'SUP-1: jogi felszólítás',
    ]));

    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/platform')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Platform/Console')
            ->has('health.components.database')
            ->has('workspaces')
            ->has('globalSuppressions', 1)
            ->where('globalSuppressions.0.email', 'globalis@spamtrap.example')
            ->where('impersonation', null)
        );
});

it('a felületről indított TÁMOGATÓI NÉZET átveszi a tenant kontextusát — és megtiltja az írást', function () {
    /*
     | Ez a C2 két nevesített előfeltételének a záróköve: a nézet HTTP-n indul, a
     | `EnforceImpersonation` middleware pedig minden nem biztonságos metódust tilt —
     | KIVÉVE a nézet lezárását, mert abból ki kell tudni lépni.
     */
    $megfigyelt = Workspace::factory()->create();
    $sajat = Workspace::factory()->create();

    $admin = consoleAdmin();
    $sajat->members()->create(['user_id' => $admin->getKey(), 'roles' => ['owner']]);

    $this->actingAs($admin)->withSession(['workspace_uuid' => $sajat->uuid])
        ->post('/platform/impersonation', [
            'workspace' => $megfigyelt->uuid,
            'reason' => 'SUP-77: a kliens nem látja a naplóját',
        ])
        ->assertRedirect('/dashboard');

    // A NÉZET ÉL: a kezdőképernyő a MEGFIGYELT tenanté.
    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.uuid', $megfigyelt->uuid)
            ->where('impersonation.active', true)
        );

    // ÍRÁS TILOS — bármelyik tenant-végponton.
    $this->actingAs($admin)
        ->post('/suppressions', ['email' => 'x@pelda.hu', 'reason' => 'manual', 'appliesTo' => 'all'])
        ->assertForbidden();

    // …a LEZÁRÁS viszont megy, különben nem lehetne kilépni a nézetből.
    $this->actingAs($admin)
        ->post('/platform/impersonation/end')
        ->assertRedirect('/platform');

    expect(app(Impersonation::class)->active($admin->fresh()))->toBeNull();
});

it('a plan-override a felületről megadható és visszavonható — INDOKKAL és LEJÁRATTAL', function () {
    $workspace = Workspace::factory()->create();
    $admin = consoleAdmin();
    $workspace->members()->create(['user_id' => $admin->getKey(), 'roles' => ['owner']]);

    // INDOK NÉLKÜL nem megy.
    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/platform')
        ->post('/platform/overrides', [
            'workspace' => $workspace->uuid,
            'features' => ['journeys'],
            'reason' => '',
            'expiresAt' => now()->addDays(7)->toDateTimeString(),
        ])
        ->assertSessionHasErrors('reason');

    // MÚLTBELI lejárattal sem: az azonnal halott override vagy elgépelés, vagy szándékos
    // látszat-intézkedés — egyik sem működik csendben.
    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/platform')
        ->post('/platform/overrides', [
            'workspace' => $workspace->uuid,
            'features' => ['journeys'],
            'reason' => 'SUP-9: próbaidő',
            'expiresAt' => now()->subDay()->toDateTimeString(),
        ])
        ->assertSessionHasErrors('expiresAt');

    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/platform')
        ->post('/platform/overrides', [
            'workspace' => $workspace->uuid,
            'features' => ['journeys'],
            'sendQuota' => 500000,
            'reason' => 'SUP-9: próbaidő a journey-re',
            'expiresAt' => now()->addDays(7)->toDateTimeString(),
        ])
        ->assertSessionHas('success');

    $override = PlanOverride::query()->firstOrFail();

    expect($override->send_quota)->toBe(500000);

    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/platform')
        ->delete('/platform/overrides/'.$override->uuid)
        ->assertSessionHas('success');

    expect($override->fresh()->revoked_at)->not->toBeNull();
});

it('a globális tiltás a felületről INDOKLÁSSAL vehető fel és oldható fel', function () {
    $workspace = Workspace::factory()->create();
    $admin = consoleAdmin();
    $workspace->members()->create(['user_id' => $admin->getKey(), 'roles' => ['owner']]);

    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/platform')
        ->post('/platform/suppressions', ['email' => 'csapda@spamtrap.example', 'reason' => 'policy', 'detail' => ''])
        ->assertSessionHasErrors('detail');

    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/platform')
        ->post('/platform/suppressions', [
            'email' => 'csapda@spamtrap.example',
            'reason' => 'policy',
            'detail' => 'SUP-40: spam-csapda',
        ])
        ->assertSessionHas('success');

    $suppression = Suppression::query()->withoutGlobalScopes()->whereNull('workspace_id')->firstOrFail();

    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/platform')
        ->delete('/platform/suppressions/'.$suppression->uuid, ['detail' => 'SUP-40: a felszólítás visszavonva'])
        ->assertSessionHas('success');

    expect(Suppression::query()->withoutGlobalScopes()->whereNull('workspace_id')->count())->toBe(0);
});

it('az audit-kereső szűr, és a keresés MAGA is nyomot hagy', function () {
    $workspace = Workspace::factory()->create();
    $admin = consoleAdmin();
    $workspace->members()->create(['user_id' => $admin->getKey(), 'roles' => ['owner']]);

    app(Impersonation::class)->start($admin, $workspace, 'SUP-80: legyen mit találni');

    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/platform/audit?action=platform.')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Platform/Audit')
            ->has('items')
            ->where('filters.action', 'platform.')
        );

    // A KERESÉS NYOMA: platform-szintű sor, nem egy véletlen tenant naplójában.
    $searchRow = AuditLog::query()
        ->where('action', 'platform.audit_searched')
        ->firstOrFail();

    expect($searchRow->workspace_id)->toBeNull()
        ->and($searchRow->actor_email)->toBe($admin->email);
});
