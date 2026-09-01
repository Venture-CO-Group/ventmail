<?php

declare(strict_types=1);

use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | Globális (platform-szintű) sor: minden tenant LÁTJA, de tenant-adat sosem szivárog.
 | Írni csak explicit szándéknyilvánítással lehet — különben egy sima tenant-kérés
 | mindenkire érvényes tiltást hozhatna létre.
 */

it('a globális suppression minden workspace-ben látszik', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    Suppression::onPlatform(fn () => Suppression::create([
        'email' => 'tiltott@example.test',
        'reason' => 'policy',
    ]));

    app(WorkspaceContext::class)->set($a);
    expect(Suppression::count())->toBe(1);

    app(WorkspaceContext::class)->set($b);
    expect(Suppression::count())->toBe(1);
});

it('a workspace-suppression nem látszik másik tenantban', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    app(WorkspaceContext::class)->set($a);
    Suppression::create(['email' => 'a@example.test', 'reason' => 'bounce']);

    app(WorkspaceContext::class)->set($b);
    expect(Suppression::count())->toBe(0);
});

it('platform-sort csak szándéknyilvánítással lehet írni', function () {
    $a = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($a);

    $row = Suppression::create(['email' => 'sajat@example.test', 'reason' => 'manual']);

    expect($row->workspace_id)->toBe($a->id);
    expect($row->isPlatformWide())->toBeFalse();
});

it('kontextus nélkül tenant-sor nem jön létre', function () {
    app(WorkspaceContext::class)->forget();

    expect(fn () => Suppression::create(['email' => 'x@example.test', 'reason' => 'manual']))
        ->toThrow(RuntimeException::class);
});

it('kontextus nélkül csak a platform-sorok látszanak', function () {
    $a = Workspace::factory()->create();

    app(WorkspaceContext::class)->set($a);
    Suppression::create(['email' => 'tenant@example.test', 'reason' => 'bounce']);

    Suppression::onPlatform(fn () => Suppression::create(['email' => 'global@example.test', 'reason' => 'policy']));

    app(WorkspaceContext::class)->forget();

    expect(Suppression::pluck('email')->all())->toBe(['global@example.test']);
});

it('az e-mail normalizált és hashelt', function () {
    $a = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($a);

    $row = Suppression::create(['email' => '  Vegyes@Example.TEST ', 'reason' => 'manual']);

    expect($row->email)->toBe('vegyes@example.test');
    expect($row->email_hash)->toBe(hash('sha256', 'vegyes@example.test'));
});
