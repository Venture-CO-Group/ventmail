<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
 | A kérésenkénti workspace-feloldás: enélkül a global scope-nak nincs kontextusa, és
 | minden tenant-lekérdezés üresen (fail-closed) térne vissza. A middleware egyben a
 | tagság-ellenőrzés helye is — tagság nélkül 403.
 */

beforeEach(function () {
    Route::middleware(['web', 'workspace'])->get('/teszt/workspace', function (WorkspaceContext $context) {
        return ['workspace' => $context->workspace()->uuid];
    });
});

it('a session-ben kiválasztott workspace-t oldja fel', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'roles' => [Role::Owner->value]]);

    $this->actingAs($user)
        ->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/teszt/workspace')
        ->assertOk()
        ->assertJson(['workspace' => $workspace->uuid]);
});

it('választás nélkül az első tagságot használja', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'roles' => [Role::Analyst->value]]);

    $this->actingAs($user)->get('/teszt/workspace')
        ->assertOk()
        ->assertJson(['workspace' => $workspace->uuid]);
});

it('idegen workspace-re EXPLICIT váltáskor 403', function () {
    $mine = Workspace::factory()->create();
    $foreign = Workspace::factory()->create();
    $user = User::factory()->create();
    $mine->members()->create(['user_id' => $user->id, 'roles' => [Role::Owner->value]]);

    $this->actingAs($user)
        ->get('/teszt/workspace?workspace='.$foreign->uuid)
        ->assertForbidden();
});

it('elavult session-választás nem zárja ki a felhasználót', function () {
    // Ha időközben eltávolították a session-ben tárolt workspace-ből, ne ragadjon 403-ban:
    // a stale értéket takarítjuk, és visszaesünk az első érvényes tagságra.
    $mine = Workspace::factory()->create();
    $foreign = Workspace::factory()->create();
    $user = User::factory()->create();
    $mine->members()->create(['user_id' => $user->id, 'roles' => [Role::Owner->value]]);

    $this->actingAs($user)
        ->withSession(['workspace_uuid' => $foreign->uuid])
        ->get('/teszt/workspace')
        ->assertOk()
        ->assertJson(['workspace' => $mine->uuid]);
});

it('a soft delete-elt workspace tagsága nem használható', function () {
    $deleted = Workspace::factory()->create();
    $live = Workspace::factory()->create();
    $user = User::factory()->create();

    $deleted->members()->create(['user_id' => $user->id, 'roles' => [Role::Owner->value]]);
    $live->members()->create(['user_id' => $user->id, 'roles' => [Role::Owner->value]]);
    $deleted->delete();

    $this->actingAs($user)->get('/teszt/workspace')
        ->assertOk()
        ->assertJson(['workspace' => $live->uuid]);
});

it('tagság nélkül 403', function () {
    Workspace::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/teszt/workspace')->assertForbidden();
});

it('bejelentkezés nélkül a belépéshez irányít', function () {
    $this->get('/teszt/workspace')->assertRedirect('/auth/redirect');
});
