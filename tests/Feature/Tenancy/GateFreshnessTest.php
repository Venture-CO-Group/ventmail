<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/*
 | A queue worker minden job között eldobja a `scoped` példányokat (forgetScopedInstances).
 | Ha a Gate closure a RÉGI WorkspaceContextet zárja magába, az adat B-re szűrve jön, a
 | jogosultsági döntés viszont A szerepeivel születik — cross-tenant jogosultság-emelés
 | pontosan azon a rétegen, amin az M0-3 küldőmotor futni fog.
 */

it('a jogosultsági döntés a FRISS kontextusból születik', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();
    $user = User::factory()->create();

    $a->members()->create(['user_id' => $user->id, 'roles' => [Role::Owner->value]]);
    $b->members()->create(['user_id' => $user->id, 'roles' => [Role::Analyst->value]]);

    app(WorkspaceContext::class)->set($a);
    expect(Gate::forUser($user)->allows('campaign.publish'))->toBeTrue();

    // Ahogy a Horizon teszi két job között:
    app()->forgetScopedInstances();

    app(WorkspaceContext::class)->set($b);
    expect(Gate::forUser($user)->allows('campaign.publish'))
        ->toBeFalse('a Gate a régi workspace kontextusát használta');
});

it('kontextus nélkül a Gate mindent tilt', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'roles' => [Role::Owner->value]]);

    app()->forgetScopedInstances();

    expect(Gate::forUser($user)->allows('workspace.manage'))->toBeFalse();
});
