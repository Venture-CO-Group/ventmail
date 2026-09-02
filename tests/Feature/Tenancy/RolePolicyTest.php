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
 | M0-1 elfogadási kritérium #9 — a hat szerep képességmátrixa (spec 3.3).
 | Kiemelten: `developer` API-kulcsot/SMTP-hitelesítőt/webhookot kezel, de KAMPÁNYHOZ NEM nyúl;
 | `author` csak draftot; `analyst` mindenre olvasó.
 */

function actingWithRole(Role $role): array
{
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $workspace->members()->create(['user_id' => $user->id, 'roles' => [$role->value]]);

    app(WorkspaceContext::class)->set($workspace);

    return [$user, $workspace];
}

dataset('szerepek', [
    // szerep,              képesség,                elvárás
    [Role::Owner,     'workspace.manage',     true],
    [Role::Owner,     'apiKey.manage',        true],
    [Role::Owner,     'campaign.publish',     true],
    [Role::Admin,     'workspace.manage',     true],
    [Role::Admin,     'campaign.publish',     true],
    [Role::Admin,     'billing.manage',       false],

    [Role::Developer, 'apiKey.manage',        true],
    [Role::Developer, 'smtpCredential.manage', true],
    [Role::Developer, 'webhook.manage',       true],
    [Role::Developer, 'logs.view',            true],
    [Role::Developer, 'campaign.create',      false],
    [Role::Developer, 'campaign.publish',     false],

    [Role::Editor,    'campaign.create',      true],
    [Role::Editor,    'campaign.publish',     true],
    [Role::Editor,    'apiKey.manage',        false],

    [Role::Author,    'campaign.create',      true],
    [Role::Author,    'campaign.publish',     false],
    [Role::Author,    'apiKey.manage',        false],

    [Role::Analyst,   'analytics.view',       true],
    [Role::Analyst,   'logs.view',            true],
    [Role::Analyst,   'campaign.create',      false],
    [Role::Analyst,   'campaign.publish',     false],
    [Role::Analyst,   'apiKey.manage',        false],
    [Role::Analyst,   'workspace.manage',     false],
]);

it('a szerep-képesség mátrix érvényes', function (Role $role, string $ability, bool $expected) {
    [$user] = actingWithRole($role);

    expect(Gate::forUser($user)->allows($ability))->toBe(
        $expected,
        sprintf('%s → %s elvárt: %s', $role->value, $ability, $expected ? 'engedve' : 'tiltva')
    );
})->with('szerepek');

it('tagság nélkül semmit nem lehet', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    app(WorkspaceContext::class)->set($workspace);

    foreach (['workspace.manage', 'apiKey.manage', 'campaign.create', 'logs.view', 'analytics.view'] as $ability) {
        expect(Gate::forUser($user)->allows($ability))->toBeFalse("tagság nélkül engedve: {$ability}");
    }
});

it('a másik workspace-beli tagság itt nem ér semmit', function () {
    $home = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $user = User::factory()->create();

    $home->members()->create(['user_id' => $user->id, 'roles' => [Role::Owner->value]]);

    app(WorkspaceContext::class)->set($other);

    expect(Gate::forUser($user)->allows('workspace.manage'))->toBeFalse();
});

it('minden szerep ismert és pontosan hat van', function () {
    expect(Role::values())->toHaveCount(6)
        ->toEqualCanonicalizing(['owner', 'admin', 'developer', 'editor', 'author', 'analyst']);
});
