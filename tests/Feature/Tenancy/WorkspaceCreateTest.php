<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | v1.1 · SHELL — „Új workspace" a váltóból.
 |
 | A létrehozó TULAJDONOS lesz, a művelet auditált, és a váltó rögtön az új workspace-re áll.
 | Az új workspace üres: más tenant adatához semmi nem kapcsolja.
 */

function creator(): array
{
    $workspace = Workspace::factory()->create(['name' => 'Meglevo']);
    $user = User::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'roles' => ['analyst']]);

    return [$user, $workspace];
}

it('új workspace-t hoz létre, tulajdonosként, auditálva, és átvált rá', function () {
    [$user, $current] = creator();

    $response = $this->actingAs($user)->withSession(['workspace_uuid' => $current->uuid])
        ->post('/workspaces', ['name' => '  Új Cég Kft.  ']);

    $response->assertRedirect('/dashboard')->assertSessionHasNoErrors();

    $created = Workspace::query()->where('name', 'Új Cég Kft.')->firstOrFail();

    $member = WorkspaceMember::query()->where('workspace_id', $created->id)->where('user_id', $user->id)->firstOrFail();

    expect($member->roles())->toBe(['owner'])
        ->and(session('workspace_uuid'))->toBe((string) $created->uuid)
        ->and($created->slug)->toStartWith('uj-ceg-kft-');

    $audit = AuditLog::query()->where('action', 'workspace.created')->firstOrFail();

    expect($audit->user_id)->toBe($user->id)
        ->and($audit->workspace_id)->toBe($created->id);
});

it('a név kötelező és korlátos', function (string $name) {
    [$user, $current] = creator();

    $this->actingAs($user)->withSession(['workspace_uuid' => $current->uuid])
        ->post('/workspaces', ['name' => $name])
        ->assertSessionHasErrors('name');

    expect(Workspace::query()->count())->toBe(1);
})->with(['üres' => [''], 'szóköz' => ['   '], 'túl hosszú' => [str_repeat('a', 121)]]);

it('a felhasználónként birtokolt workspace-ek száma plafonos', function () {
    config(['tenancy.workspaces.max_owned' => 2]);
    [$user, $current] = creator();

    foreach (['Egy', 'Ketto'] as $name) {
        $this->actingAs($user)->withSession(['workspace_uuid' => $current->uuid])
            ->post('/workspaces', ['name' => $name])->assertSessionHasNoErrors();
    }

    $this->actingAs($user)->withSession(['workspace_uuid' => $current->uuid])
        ->post('/workspaces', ['name' => 'Harom'])
        ->assertSessionHasErrors('name');

    expect(Workspace::query()->where('name', 'Harom')->exists())->toBeFalse();
});

it('bejelentkezés nélkül nem hozható létre', function () {
    $this->post('/workspaces', ['name' => 'X'])->assertRedirect();

    expect(Workspace::query()->count())->toBe(0);
});
