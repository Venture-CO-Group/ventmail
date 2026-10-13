<?php

declare(strict_types=1);

use App\Modules\Analytics\Models\EngagementClientDaily;
use App\Modules\Analytics\Services\CampaignCharts;
use App\Modules\Analytics\Services\ClickMap;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Campaign\Models\CampaignVariant;
use App\Modules\Template\Services\TemplateVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AnalyticsFixtures;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 8. modul — kampány-grafikonok: nyitás/kattintás időben (egyedi, óra/nap vödör a
 | felhasználó időzónájában), eszköz/kliens, top linkek, kattintási hőtérkép.
 */

it('72 óra alatt órás vödör a megadott időzónában, egyedi nyitó/kattintó', function () {
    [$workspace] = AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign(['started_at' => Carbon::parse('2026-10-10 21:00:00', 'UTC')]);
    $scope = 'campaign:'.$campaign->uuid;

    StudioFixtures::engagement((int) $workspace->getKey(), 'a@pelda.hu', Carbon::parse('2026-10-10 22:15:00', 'UTC'), Carbon::parse('2026-10-10 22:20:00', 'UTC'), $scope);
    StudioFixtures::engagement((int) $workspace->getKey(), 'b@pelda.hu', Carbon::parse('2026-10-10 22:45:00', 'UTC'), null, $scope);
    StudioFixtures::engagement((int) $workspace->getKey(), 'c@pelda.hu', Carbon::parse('2026-10-11 01:05:00', 'UTC'), null, $scope);

    $timeline = app(CampaignCharts::class)->timeline($campaign, 'Europe/Budapest');
    $byHour = collect($timeline['points'])->keyBy('at');

    // 22:15 UTC = 00:15 Budapesten (CEST, +2) → a 11-ei nap 00 órája.
    expect($timeline['bucket'])->toBe('hour')
        ->and($byHour['2026-10-11T00:00']['opens'])->toBe(2)
        ->and($byHour['2026-10-11T00:00']['clicks'])->toBe(1)
        ->and($byHour['2026-10-11T03:00']['opens'])->toBe(1)
        ->and($timeline['points'][0]['at'])->toBe('2026-10-10T23:00');
});

it('72 óra fölött napos a vödör', function () {
    [$workspace] = AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign(['started_at' => now()->subDays(10)]);
    $scope = 'campaign:'.$campaign->uuid;

    StudioFixtures::engagement((int) $workspace->getKey(), 'a@pelda.hu', now()->subDays(9), null, $scope);
    StudioFixtures::engagement((int) $workspace->getKey(), 'b@pelda.hu', now()->subDay(), null, $scope);

    $timeline = app(CampaignCharts::class)->timeline($campaign, 'UTC');

    expect($timeline['bucket'])->toBe('day')
        ->and(array_sum(array_column($timeline['points'], 'opens')))->toBe(2)
        ->and(count($timeline['points']))->toBeGreaterThanOrEqual(10);
});

it('a top linkek részesedése az egyedi kattintók összegére vetül, a kulcs a ClickMap kulcsa', function () {
    AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign();
    $map = app(ClickMap::class);

    foreach (range(1, 3) as $i) {
        $map->record((string) Str::uuid7(), (string) $campaign->uuid, null, 'https://pelda.hu/foglalas', "k{$i}@pelda.hu");
    }

    $map->record((string) Str::uuid7(), (string) $campaign->uuid, null, 'https://pelda.hu/osz', 'k1@pelda.hu');

    $links = app(CampaignCharts::class)->topLinks($campaign);

    expect($links[0]['url'])->toBe('https://pelda.hu/foglalas')
        ->and($links[0]['unique'])->toBe(3)
        ->and($links[0]['share'])->toBe(75.0)
        ->and($links[1]['share'])->toBe(25.0)
        ->and($links[0]['key'])->toBe(KeyedHash::of('click-url', 'https://pelda.hu/foglalas'));
});

it('eszköz/kliens: üres → hasData false (v1.2 előtti kampány), különben összegzett', function () {
    [$workspace] = AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign();

    expect(app(CampaignCharts::class)->devices($campaign)['hasData'])->toBeFalse();

    foreach ([['mobile', 'apple_mail', 3], ['unknown', 'gmail', 5], ['mobile', 'apple_mail', 2]] as $i => [$device, $client, $n]) {
        EngagementClientDaily::query()->insert(['workspace_id' => $workspace->getKey(), 'scope' => 'campaign:'.$campaign->uuid, 'day' => now()->subDays($i)->toDateString(), 'kind' => 'open', 'device' => $device, 'client' => $client, 'total' => $n, 'created_at' => now(), 'updated_at' => now()]);
    }

    $devices = app(CampaignCharts::class)->devices($campaign);

    expect($devices['hasData'])->toBeTrue()
        ->and($devices['clients'])->toBe([['key' => 'apple_mail', 'total' => 5], ['key' => 'gmail', 'total' => 5]])
        ->and(collect($devices['devices'])->firstWhere('key', 'mobile')['total'])->toBe(5);
});

it('hőtérkép: a küldéskori verzió, data-vm-link = ClickMap-kulcs, sandbox CSP + nosniff', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');
    $template = AnalyticsFixtures::template();
    $versions = app(TemplateVersions::class);
    $versions->snapshot($template);

    $campaign = AnalyticsFixtures::campaign(['started_at' => now()->addSecond()], $template);

    // A küldés UTÁN a sablon változik: a hőtérkép a régi verziót mutatja, és jelzi a változást.
    Carbon::setTestNow(now()->addMinute());
    $blocks = (array) $template->blocks;
    $blocks[0]['children'][0]['children'][2]['attrs']['href'] = 'https://pelda.hu/uj-link';
    $template->forceFill(['blocks' => $blocks])->save();
    $versions->snapshot($template);
    Carbon::setTestNow();

    $response = $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/analytics/campaigns/'.$campaign->uuid.'/heatmap')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Template-Changed', '1');

    expect($response->headers->get('Content-Security-Policy'))->toStartWith('sandbox allow-same-origin;')
        ->toContain("default-src 'none'")
        ->and($response->headers->get('Content-Type'))->toStartWith('text/html');

    $html = (string) $response->getContent();

    // A PII-paraméter (email=) a kulcsból kiesik — ugyanúgy, mint a kattintás rögzítésénél.
    expect($html)->toContain('data-vm-link="'.ClickMap::linkKey('https://pelda.hu/foglalas?utm_source=hirlevel&email=a@b.hu').'"')
        ->and(ClickMap::linkKey('https://pelda.hu/foglalas?utm_source=hirlevel&email=a@b.hu'))->toBe(KeyedHash::of('click-url', 'https://pelda.hu/foglalas?utm_source=hirlevel'))
        ->and($html)->toContain('data-vm-link="'.ClickMap::linkKey('https://pelda.hu/osz').'"')
        ->and($html)->not->toContain('uj-link')
        ->and($html)->not->toContain('<script');
});

it('hőtérkép: idegen kampány 404', function () {
    AnalyticsFixtures::tenant();
    $foreign = AnalyticsFixtures::campaign();

    [$workspace, $user] = AnalyticsFixtures::tenant();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/analytics/campaigns/'.$foreign->uuid.'/heatmap')
        ->assertNotFound();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/analytics/campaigns/'.$foreign->uuid.'/links.csv')
        ->assertNotFound();
});

it('a top-link CSV a saját kampányra letölthető', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');
    $campaign = AnalyticsFixtures::campaign();
    app(ClickMap::class)->record((string) Str::uuid7(), (string) $campaign->uuid, null, 'https://pelda.hu/osz', 'k@pelda.hu');

    $response = $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/analytics/campaigns/'.$campaign->uuid.'/links.csv')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and((string) $response->getContent())->toContain('https://pelda.hu/osz');
});

it('variáns-regresszió (D241): a variáns kulcsa `label`, a győztes a címkéjével jön', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');
    $campaign = AnalyticsFixtures::campaign();

    CampaignVariant::query()->create(['campaign_id' => $campaign->getKey(), 'label' => 'A', 'subject' => 'Egy', 'weight' => 50]);
    $winner = CampaignVariant::query()->create(['campaign_id' => $campaign->getKey(), 'label' => 'B', 'subject' => 'Kettő', 'weight' => 50]);
    $winner->forceFill(['is_winner' => true])->saveQuietly();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/analytics/campaigns/'.$campaign->uuid)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Analytics/Campaign')
            ->where('variants.0.label', 'A')
            ->where('variants.1.label', 'B')
            ->where('winner', 'B')
            ->has('daily')
            ->loadDeferredProps('charts', fn (AssertableInertia $reload) => $reload->where('charts.available', true)->has('charts.timeline'))
        );

    // A felület a `label` kulcsot olvassa (nem a régi, nem létező `variant`-ot).
    $vue = (string) file_get_contents(resource_path('js/Pages/Analytics/Campaign.vue'));
    expect($vue)->not->toContain('variant.variant')->toContain('variant.label');
});
