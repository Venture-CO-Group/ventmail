<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\TenantFixture;

uses(RefreshDatabase::class);

/*
 | A parent→child váltás nem elég, hogy a KAPCSOLAT engedje: a kérő FELHASZNÁLÓNAK is
 | jogosultnak kell lennie. Enélkül egy parent-beli `analyst` is olvashatná a child
 | nyers adatait.
 */

beforeEach(function () {
    TenantFixture::createTable();

    $this->parent = Workspace::factory()->create();
    $this->child = Workspace::factory()->create();
    $this->parent->children()->attach([$this->child->id => ['permissions' => json_encode(['raw_data' => true])]]);

    app(WorkspaceContext::class)->set($this->child);
    TenantFixture::create(['label' => 'child-adat']);
    app(WorkspaceContext::class)->set($this->parent);
});

it('a parent admin olvashatja a child nyers adatait', function () {
    $user = User::factory()->create();
    $this->parent->members()->create(['user_id' => $user->id, 'roles' => [Role::Admin->value]]);
    Auth::login($user);

    $rows = app(WorkspaceContext::class)->runForChild($this->child, fn () => TenantFixture::pluck('label')->all());

    expect($rows)->toBe(['child-adat']);
});

it('a parent analystje NEM olvashatja a child nyers adatait', function () {
    $user = User::factory()->create();
    $this->parent->members()->create(['user_id' => $user->id, 'roles' => [Role::Analyst->value]]);
    Auth::login($user);

    expect(fn () => app(WorkspaceContext::class)->runForChild($this->child, fn () => TenantFixture::count()))
        ->toThrow(AuthorizationException::class);
});

it('bejelentkezett felhasználó nélkül nincs sub-account váltás', function () {
    expect(fn () => app(WorkspaceContext::class)->runForChild($this->child, fn () => TenantFixture::count()))
        ->toThrow(AuthorizationException::class);
});
