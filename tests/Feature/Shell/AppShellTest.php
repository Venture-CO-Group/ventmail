<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Enums\Role;
use App\Modules\SuperAdmin\Services\Impersonation;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/*
 | M4-0 · AZ APP-HÉJ — navigáció, workspace-váltó, nyelvválasztó, támogatói nézet.
 |
 | Ez a packet nem „képernyőt" hoz, hanem a KÖZÖS ÁLLAPOTOT, amire minden későbbi képernyő
 | épül. Ha itt csúszik el valami (rossz workspace, túl sok képesség, hiányzó tiltás), az
 | MINDEN képernyőn elcsúszik — ezért a héj tesztjei szigorúbbak, mint egy oldalé.
 */

function shellUser(Workspace $workspace, string $role = 'owner'): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'shell-'.uniqid(),
        'name' => 'Teszt Felhasználó',
        'email' => 'shell-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);

    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

    return $user;
}

it('a nyitóképernyő a KÜLDÉST MEGÁLLÍTÓ számokat mutatja', function () {
    $workspace = Workspace::factory()->create();
    $user = shellUser($workspace);

    app(WorkspaceContext::class)->runFor($workspace, function () {
        Domain::create(['name' => 'jo.hu', 'status' => 'verified', 'verified_at' => now()]);
        Domain::create(['name' => 'rossz.hu', 'status' => 'pending']);
        Suppression::create([
            'email' => 'tiltott@pelda.hu',
            'applies_to' => 'marketing',
            'reason' => 'complaint',
            'source' => 'tenant',
        ]);
    });

    $this->actingAs($user)
        ->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('domains.total', 2)
            ->where('domains.verified', 1)
            ->where('suppressions', 1)
            // A KVÓTA az EntitlementCheckeren jön, tehát a plan-override-ot is látná.
            ->has('quota.remaining')
            ->has('delivery.bounce_rate')
        );
});

it('a naplót NEM látó szerep nyitóképernyője sem szivárogtat naplót', function () {
    /*
     | Az `AbilityMap` fehérlistás, és az `author` szándékosan nem kap `logs.view`-t. Egy
     | nyitóképernyő, ami „csak összesít", pont ezt a döntést kerülné meg: a legutóbbi
     | üzenetek listája címzett-címeket és tárgyakat mutat.
     */
    $workspace = Workspace::factory()->create();
    $author = shellUser($workspace, Role::Author->value);

    $this->actingAs($author)
        ->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('delivery', null)
            ->where('recent', [])
        );
});

it('a megosztott állapot a FELOLDOTT képességeket adja, nem a szerep nevét', function () {
    // A menü ezekre épül. Ha a szerep nevét küldenénk, a felület újraértelmezné az
    // `AbilityMap`-et — és két helyen kellene karbantartani ugyanazt a szabályt.
    $workspace = Workspace::factory()->create();
    $analyst = shellUser($workspace, Role::Analyst->value);

    $this->actingAs($analyst)
        ->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('abilities', fn (Collection $abilities) => $abilities->contains('logs.view')
                && ! $abilities->contains('domain.manage'))
            ->where('workspace.uuid', $workspace->uuid)
            ->has('workspaces', 1)
            ->where('locale', 'hu')
        );
});

it('a WORKSPACE-VÁLTÁS tagságot követel, nem a kérés szavát', function () {
    $enyem = Workspace::factory()->create();
    $idegen = Workspace::factory()->create();
    $user = shellUser($enyem);

    $this->actingAs($user)
        ->withSession(['workspace_uuid' => $enyem->uuid])
        ->post('/workspace/switch', ['workspace' => $idegen->uuid])
        ->assertForbidden();

    expect(session('workspace_uuid'))->toBe($enyem->uuid);

    // A SAJÁT tagságra viszont vált.
    $masik = Workspace::factory()->create();
    $masik->members()->create(['user_id' => $user->getKey(), 'roles' => ['owner']]);

    $this->actingAs($user)
        ->withSession(['workspace_uuid' => $enyem->uuid])
        ->from('/dashboard')
        ->post('/workspace/switch', ['workspace' => $masik->uuid])
        ->assertRedirect('/dashboard');

    expect(session('workspace_uuid'))->toBe($masik->uuid);
});

it('a NYELVVÁLASZTÁS a felhasználó sorára megy, és a listához kötött', function () {
    $workspace = Workspace::factory()->create();
    $user = shellUser($workspace);

    $this->actingAs($user)->from('/dashboard')->post('/locale', ['locale' => 'de'])->assertRedirect('/dashboard');

    expect($user->fresh()->locale)->toBe('de');

    // A támogatott lista ZÁRT: idegen érték nem juthat a fordító-rétegbe.
    $this->actingAs($user)->from('/dashboard')->post('/locale', ['locale' => 'zz'])
        ->assertSessionHasErrors('locale');

    expect($user->fresh()->locale)->toBe('de');
});

/*
 | A TÁMOGATÓI NÉZET a héjban
 */

it('az impersonáló admin LÁTJA a tenant képernyőit — de csak azt az egyet', function () {
    /*
     | A `WorkspaceGate` tagság nélkül mindent tilt, és ez helyes. Az impersonation viszont
     | pont tagság NÉLKÜL ad nézetet: enélkül a támogatás bejut a tenant kontextusába, és
     | ott minden képernyőn 403-at kap — a 82. kritérium fele használhatatlan lenne.
     */
    $workspace = Workspace::factory()->create();
    $masik = Workspace::factory()->create();

    $admin = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'admin-shell',
        'name' => 'Platform Admin',
        'email' => 'admin-shell@ventmail.app',
    ]);
    $admin->forceFill(['platform_admin' => true])->save();

    app(Impersonation::class)->start($admin, $workspace, 'SUP-60: a kliens nem látja a naplóját');

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.uuid', $workspace->uuid)
            ->where('impersonation.active', true)
            // OLVASÁSI jog: a napló látszik, különben a nézetnek nincs értelme.
            ->where('abilities', fn (Collection $abilities) => $abilities->contains('logs.view'))
        );

    // …de a MÁSIK workspace-re semmi: a nézet EGY tenantra szól.
    app(WorkspaceContext::class)->runFor($masik, function () use ($admin) {
        expect(Gate::forUser($admin)->allows('logs.view'))->toBeFalse();
    });
});

it('aktív nézet alatt MINDEN nem biztonságos HTTP-metódus 403 — a lezárás kivételével', function () {
    /*
     | A modell-őr az Eloquent-eseményekre épül, amiket a query-builder megkerül. A
     | middleware ezért a KÉRÉS szintjén tilt, metódus alapján: egy jövőbeli írási végpontot
     | senki nem fog felvenni egy tiltólistára, a biztonságos alapértelmezés viszont magától
     | védi.
     */
    $workspace = Workspace::factory()->create();

    $admin = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'admin-shell-2',
        'name' => 'Platform Admin',
        'email' => 'admin-shell-2@ventmail.app',
    ]);
    $admin->forceFill(['platform_admin' => true])->save();
    $workspace->members()->create(['user_id' => $admin->getKey(), 'roles' => ['owner']]);

    // Nézet NÉLKÜL a saját tagságán ír: megy.
    $this->actingAs($admin)->from('/dashboard')
        ->post('/locale', ['locale' => 'en'])
        ->assertRedirect('/dashboard');

    app(Impersonation::class)->start($admin, $workspace, 'SUP-61: nézzük meg');

    $this->actingAs($admin)->from('/dashboard')
        ->post('/locale', ['locale' => 'hu'])
        ->assertForbidden();

    // Az OLVASÁS viszont megy — a támogatás LÁT.
    $this->actingAs($admin)->get('/dashboard')->assertOk();
});
