<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\ReportArtifactStore;
use App\Modules\Analytics\Models\AnalyticsReport;
use App\Modules\Analytics\Testing\FakePdfRenderer;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\AnalyticsFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 8. modul — a lejárt riport FÁJLJA törlődik, a sor `expired`-ként marad; idempotens,
 | minden workspace-en, a tenant-kontextuson belül.
 */

it('a lejárt fájl törlődik, a sor expired, a friss érintetlen; másodszor nincs teendő', function () {
    Storage::fake('local');
    $store = app(ReportArtifactStore::class);
    $made = [];

    foreach (['old' => now()->subHour(), 'fresh' => now()->addDays(3)] as $label => $expires) {
        [$workspace, $user] = AnalyticsFixtures::tenant();
        $uuid = (string) Str::uuid();
        $path = $store->put((string) $workspace->uuid, $uuid, FakePdfRenderer::minimalPdf(1));
        (new AnalyticsReport)->forceFill(['uuid' => $uuid, 'requested_by' => $user->getKey(), 'kind' => 'period', 'timezone' => 'UTC', 'locale' => 'hu', 'sections' => ['kpis'], 'params_hash' => str_repeat('e', 64), 'status' => 'ready', 'file_path' => $path, 'expires_at' => $expires])->save();
        $made[$label] = [$workspace, $path, $uuid];
    }

    app(WorkspaceContext::class)->forget();

    $this->artisan('analytics:purge-reports')->expectsOutputToContain('Lejárt riport: 1')->assertSuccessful();

    Storage::disk('local')->assertMissing($made['old'][1]);
    Storage::disk('local')->assertExists($made['fresh'][1]);

    app(WorkspaceContext::class)->set($made['old'][0]);
    $old = AnalyticsReport::query()->where('uuid', $made['old'][2])->sole();
    expect($old->status)->toBe('expired')->and($old->file_path)->toBeNull();

    app(WorkspaceContext::class)->forget();
    $this->artisan('analytics:purge-reports')->expectsOutputToContain('Lejárt riport: 0')->assertSuccessful();
});

it('óránként ütemezett', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'analytics:purge-reports'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 * * * *');
});
