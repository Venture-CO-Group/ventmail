<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TenantFixture;

uses(RefreshDatabase::class);

/*
 | M0-1 elfogadási kritérium #7 és #8 — tenant- ÉS sub-account izoláció.
 | Leak = blocking (golden rule 6).
 */

beforeEach(function () {
    // Próbatábla egy tipikus workspace-hez kötött entitáshoz.
    TenantFixture::createTable();
});

it('másik workspace usere nulla sort lát', function () {
    $a = Workspace::factory()->create(['name' => 'A']);
    $b = Workspace::factory()->create(['name' => 'B']);

    $context = app(WorkspaceContext::class);

    $context->set($a);
    TenantFixture::create(['label' => 'A-titok']);
    TenantFixture::create(['label' => 'A-masik']);

    $context->set($b);
    TenantFixture::create(['label' => 'B-sajat']);

    expect(TenantFixture::count())->toBe(1);
    expect(TenantFixture::pluck('label')->all())->toBe(['B-sajat']);

    $context->set($a);
    expect(TenantFixture::count())->toBe(2);
});

it('kontextus nélkül semmit nem enged át', function () {
    $a = Workspace::factory()->create();

    app(WorkspaceContext::class)->set($a);
    TenantFixture::create(['label' => 'valami']);

    app(WorkspaceContext::class)->forget();

    // Fail-closed: a biztonságos alapállapot az üres halmaz, nem a teljes tábla.
    expect(TenantFixture::count())->toBe(0);
});

it('a workspace_id automatikusan a kontextusból töltődik', function () {
    $a = Workspace::factory()->create();

    app(WorkspaceContext::class)->set($a);
    $row = TenantFixture::create(['label' => 'auto']);

    expect($row->workspace_id)->toBe($a->id);
});

it('idegen workspace_id-vel sem lehet átszivárogtatni', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    app(WorkspaceContext::class)->set($a);
    TenantFixture::create(['label' => 'a-sor']);

    app(WorkspaceContext::class)->set($b);

    // Explicit idegen kulcsra szűrve sem jöhet vissza a másik tenant sora.
    expect(TenantFixture::where('workspace_id', $a->id)->count())->toBe(0);
    expect(TenantFixture::find(1))->toBeNull();
});

it('a próbatábla tényleg workspace-hez kötött', function () {
    expect(Schema::hasColumn('tenant_fixtures', 'workspace_id'))->toBeTrue();
});

it('explicit idegen workspace_id-vel sem lehet ÍRNI', function () {
    // A global scope csak SELECT/UPDATE/DELETE-re fut, INSERT-re nem: explicit workspace_id-vel
    // egy tenant a másik adatterébe írhatott. Írás irányú leak = blocking (golden rule 6).
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    app(WorkspaceContext::class)->set($b);

    // Nem a tömeges-hozzárendelés véd (az csak eldobná a mezőt), hanem a mentési őr:
    // közvetlenül állítjuk be az attribútumot.
    expect(function () use ($a) {
        $row = new TenantFixture(['label' => 'atszivargas']);
        $row->workspace_id = $a->id;
        $row->save();
    })->toThrow(RuntimeException::class, 'Idegen workspace_id');

    app(WorkspaceContext::class)->set($a);
    expect(TenantFixture::count())->toBe(0);
});

it('a workspace_id utólag sem írható át idegen tenantra', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    app(WorkspaceContext::class)->set($a);
    $row = TenantFixture::create(['label' => 'a-sor']);

    expect(function () use ($row, $b) {
        $row->workspace_id = $b->id;
        $row->save();
    })->toThrow(RuntimeException::class);
});

it('kontextus nélkül nem jön létre tenant-sor', function () {
    app(WorkspaceContext::class)->forget();

    expect(fn () => TenantFixture::create(['label' => 'gazdatlan']))
        ->toThrow(RuntimeException::class);
});
