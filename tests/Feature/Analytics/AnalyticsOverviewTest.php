<?php

declare(strict_types=1);

use App\Modules\Analytics\Data\AnalyticsRange;
use App\Modules\Analytics\Services\AnalyticsSections;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\QueryBudget;
use App\Support\QueryTooExpensive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AnalyticsFixtures;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 8. modul — az /analytics oldal: szekciónként halasztott (deferred) propok, a képernyő
 | és a riport közös forrása (`AnalyticsSections`), tenant- és sub-account-izoláció, keret.
 */

it('a szekciók halasztott propként jönnek, a betöltött alakjuk teljes', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');
    $campaign = AnalyticsFixtures::campaign(['name' => 'Őszi']);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/analytics?range=90')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Analytics/Index')
            ->has('campaigns', 1)
            ->where('selection.campaign', (string) $campaign->uuid)
            ->where('selection.range', '90')
            ->where('selection.days', 90)
            ->where('canManageBranding', false)
            ->missing('campaignSection')
            ->loadDeferredProps(['campaign', 'audience', 'journey', 'platform'], fn (AssertableInertia $reload) => $reload
                ->where('campaignSection.available', true)
                ->where('campaignSection.campaign.name', 'Őszi')
                ->has('campaignSection.timeline.points')
                ->has('campaignSection.devices.clients')
                ->has('campaignSection.links')
                ->where('audienceSection.available', true)
                ->has('audienceSection.growth', 90)
                ->has('audienceSection.churn')
                ->has('audienceSection.engagement', 4)
                ->where('journeySection', null)
                ->where('platformSection.available', true)
                ->has('platformSection.bounces.classes')
                ->where('platformSection.complaintThreshold', 0.3)
            )
        );
});

it('egyedi időszak: érvénytelen vagy 366 napnál hosszabb → 30 napos alapértelmezés', function () {
    $tz = 'Europe/Budapest';

    expect(AnalyticsRange::resolve('custom', '2026-01-01', '2026-01-10', $tz)->days())->toBe(10)
        ->and(AnalyticsRange::resolve('custom', now()->subDays(400)->toDateString(), now()->toDateString(), $tz)->key)->toBe('30')
        ->and(AnalyticsRange::resolve('custom', now()->toDateString(), now()->addDay()->toDateString(), $tz)->key)->toBe('30')
        ->and(AnalyticsRange::resolve('custom', '2026-02-30', '2026-03-01', $tz)->key)->toBe('30')
        ->and(AnalyticsRange::resolve('999', null, null, $tz)->key)->toBe('30');
});

it('idegen tenant kampánya nem látszik, a kérésben megadva 404', function () {
    [$foreign] = AnalyticsFixtures::tenant();
    $foreignCampaign = AnalyticsFixtures::campaign();

    [$workspace, $user] = AnalyticsFixtures::tenant('author');

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/analytics')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('campaigns', 0)->where('selection.campaign', null));

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/analytics?campaign='.$foreignCampaign->uuid)
        ->assertNotFound();
});

it('parent és child (sub-account) számai nem keverednek', function () {
    [$parent, $user] = AnalyticsFixtures::tenant('owner');
    $child = Workspace::factory()->create();
    DB::table('workspace_parents')->insert(['parent_workspace_id' => $parent->getKey(), 'child_workspace_id' => $child->getKey(), 'permissions' => json_encode(['raw_data' => true])]);
    $child->members()->create(['user_id' => $user->getKey(), 'roles' => ['owner']]);

    app(WorkspaceContext::class)->set($child);
    $childCampaign = AnalyticsFixtures::campaign(['name' => 'Child kampány']);
    StudioFixtures::engagement((int) $child->getKey(), 'x@pelda.hu', now(), null, 'campaign:'.$childCampaign->uuid);

    app(WorkspaceContext::class)->set($parent);
    expect(Campaign::query()->count())->toBe(0);

    $this->actingAs($user)->withSession(['workspace_uuid' => $parent->uuid])
        ->get('/analytics')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('campaigns', 0));

    $this->actingAs($user)->withSession(['workspace_uuid' => $parent->uuid])
        ->get('/analytics?campaign='.$childCampaign->uuid)
        ->assertNotFound();

    $this->actingAs($user)->withSession(['workspace_uuid' => $child->uuid])
        ->get('/analytics')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('campaigns', 1)
            ->loadDeferredProps('campaign', fn (AssertableInertia $reload) => $reload->where('campaignSection.summary.unique_opened', 1)));
});

it('a keret túllépése available:false, és nem kerül gyorsítótárba', function () {
    AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign();

    app()->instance(QueryBudget::class, new class extends QueryBudget
    {
        public function run(callable $work, ?int $milliseconds = null): mixed
        {
            throw new QueryTooExpensive('teszt');
        }
    });

    expect(app(AnalyticsSections::class)->campaign($campaign, 'UTC'))->toBe(['available' => false]);

    app()->forgetInstance(QueryBudget::class);
    app()->forgetInstance(AnalyticsSections::class);
    app()->bind(QueryBudget::class, QueryBudget::class);

    expect(app(AnalyticsSections::class)->campaign($campaign, 'UTC')['available'])->toBeTrue();
});

it('a szekció kontextus nélkül nem fut (fail-closed)', function () {
    AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign();
    app(WorkspaceContext::class)->forget();

    app(AnalyticsSections::class)->campaign($campaign, 'UTC');
})->throws(RuntimeException::class);

it('a nem-tag a session-be írt idegen workspace adatát sem látja', function () {
    [$workspace] = AnalyticsFixtures::tenant();
    AnalyticsFixtures::campaign();
    $outsider = StudioFixtures::user(Workspace::factory()->create());

    $this->actingAs($outsider)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/analytics')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('campaigns', 0));
});
