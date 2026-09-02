<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Actions\AssignWorkspaceRoles;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
 | Privilege escalation elleni invariáns: „nem adhatsz olyan szerepet, amivel magad nem
 | rendelkezel". Az `admin` szándékosan nem kap `billing.manage`-et — ha kioszthatná
 | magának az `owner`-t, a korlát semmit nem érne.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    $this->target = User::factory()->create();
});

function actingAsMember(Workspace $workspace, Role ...$roles): User
{
    $user = User::factory()->create();
    $workspace->members()->create([
        'user_id' => $user->id,
        'roles' => array_map(fn (Role $role) => $role->value, $roles),
    ]);
    Auth::login($user);

    return $user;
}

it('az admin nem oszthat ki owner szerepet', function () {
    actingAsMember($this->workspace, Role::Admin);

    expect(fn () => app(AssignWorkspaceRoles::class)($this->target, [Role::Owner->value]))
        ->toThrow(AuthorizationException::class);
});

it('az owner kioszthat owner szerepet', function () {
    actingAsMember($this->workspace, Role::Owner);

    $member = app(AssignWorkspaceRoles::class)($this->target, [Role::Owner->value]);

    expect($member->roles())->toBe([Role::Owner->value]);
});

it('az admin kioszthat developer szerepet', function () {
    actingAsMember($this->workspace, Role::Admin);

    $member = app(AssignWorkspaceRoles::class)($this->target, [Role::Developer->value]);

    expect($member->roles())->toBe([Role::Developer->value]);
});

it('ismeretlen szerep nem menthető', function () {
    actingAsMember($this->workspace, Role::Owner);

    expect(fn () => app(AssignWorkspaceRoles::class)($this->target, ['szuperfonok']))
        ->toThrow(InvalidArgumentException::class);
});

it('tagságkezelési jog nélkül nem megy', function () {
    actingAsMember($this->workspace, Role::Developer);

    expect(fn () => app(AssignWorkspaceRoles::class)($this->target, [Role::Analyst->value]))
        ->toThrow(AuthorizationException::class);
});

it('a tagság mindig az aktuális workspace-be kerül', function () {
    actingAsMember($this->workspace, Role::Owner);
    $masik = Workspace::factory()->create();

    $member = app(AssignWorkspaceRoles::class)($this->target, [Role::Editor->value]);

    expect($member->workspace_id)->toBe($this->workspace->id)
        ->not->toBe($masik->id);
});
