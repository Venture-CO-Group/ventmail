<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DemoDomain;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | D292 — a DEMÓ-domain (dev-seedek) KÖVETKEZETES: ha `verified` (a küldőkapu ezt kéri), akkor a
 | rekordjai is rendben vannak. Korábban „Rendben" jelvény állt „0 / 5 rekord rendben" mellett.
 */
it('a demó-domain hitelesített, és mind az öt számolt rekordja rendben van', function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());

    $domain = DemoDomain::ensure();

    expect($domain->status)->toBe('verified')
        ->and($domain->verified_at)->not->toBeNull()
        ->and([$domain->spf_status, $domain->dkim_status, $domain->dmarc_status, $domain->return_path_status, $domain->tracking_status])
        ->each->toBe('verified');
});

it('idempotens, és egy korábbi, ellentmondásos demó-domaint is rendbe tesz', function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    DemoDomain::ensure();
    $domain = DemoDomain::ensure();

    expect(Domain::query()->where('name', 'pelda.hu')->count())->toBe(1)
        ->and($domain->dkim_status)->toBe('verified');
});

it('mindhárom dev-seed a közös demó-domaint használja', function () {
    foreach ([
        app_path('Modules/Identity/Http/Controllers/DevSeedController.php'),
        app_path('Modules/Audience/Http/Controllers/DevStudioSeedController.php'),
        app_path('Modules/Analytics/Http/Controllers/DevSeedAnalyticsController.php'),
    ] as $file) {
        $source = (string) file_get_contents($file);

        expect($source)->toContain('DemoDomain::ensure()')
            ->and($source)->not->toContain("firstOrCreate(['name' => 'pelda.hu']");
    }
});
