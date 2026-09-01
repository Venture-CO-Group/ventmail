<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TenantFixture;

uses(RefreshDatabase::class);

/*
 | Sub-account (spec 3.2): a parent összesített használatot lát és kezelhet a childok
 | nevében; a childok EGYMÁST soha. Leak = blocking.
 */

beforeEach(function () {
    TenantFixture::createTable();

    $this->parent = Workspace::factory()->create(['name' => 'Ügynökség']);
    $this->childOne = Workspace::factory()->create(['name' => 'Ügyfél 1']);
    $this->childTwo = Workspace::factory()->create(['name' => 'Ügyfél 2']);

    $this->parent->children()->attach([
        $this->childOne->id => ['permissions' => json_encode(['raw_data' => true])],
        $this->childTwo->id => ['permissions' => json_encode(['raw_data' => false])],
    ]);

    $context = app(WorkspaceContext::class);

    $context->set($this->childOne);
    TenantFixture::create(['label' => 'child-1-adat']);

    $context->set($this->childTwo);
    TenantFixture::create(['label' => 'child-2-adat']);

    $context->forget();
});

it('a child nem látja a testvér child adatait', function () {
    app(WorkspaceContext::class)->set($this->childOne);

    expect(TenantFixture::pluck('label')->all())->toBe(['child-1-adat']);

    app(WorkspaceContext::class)->set($this->childTwo);

    expect(TenantFixture::pluck('label')->all())->toBe(['child-2-adat']);
});

it('a parent saját kontextusában nem látja a childok nyers adatát', function () {
    app(WorkspaceContext::class)->set($this->parent);

    expect(TenantFixture::count())->toBe(0);
});

it('a parent összesített használatot lát a childjairól', function () {
    app(WorkspaceContext::class)->set($this->parent);

    $ids = $this->parent->accessibleWorkspaceIds();

    expect($ids)->toHaveCount(3);
    expect($ids->contains($this->childOne->id))->toBeTrue();
    expect($ids->contains($this->childTwo->id))->toBeTrue();
});

it('a child nem lát rá a parent hierarchiájára', function () {
    expect($this->childOne->accessibleWorkspaceIds()->all())->toBe([$this->childOne->id]);
    expect($this->childOne->isChildOf($this->parent))->toBeTrue();
    expect($this->childOne->isChildOf($this->childTwo))->toBeFalse();
});

it('a parent csak explicit jogosultsággal olvashat child nyers adatot', function () {
    $context = app(WorkspaceContext::class);
    $context->set($this->parent);

    // raw_data = true → megnyitható a child kontextusa
    $rows = $context->runForChild($this->childOne, fn () => TenantFixture::pluck('label')->all());
    expect($rows)->toBe(['child-1-adat']);

    // raw_data = false → tilos
    expect(fn () => $context->runForChild($this->childTwo, fn () => TenantFixture::count()))
        ->toThrow(AuthorizationException::class);

    // a váltás után visszaáll a parent kontextusa
    expect($context->workspaceId())->toBe($this->parent->id);
});

it('idegen workspace-re a parent sem válthat', function () {
    $idegen = Workspace::factory()->create();
    $context = app(WorkspaceContext::class);
    $context->set($this->parent);

    expect(fn () => $context->runForChild($idegen, fn () => TenantFixture::count()))
        ->toThrow(AuthorizationException::class);
});
