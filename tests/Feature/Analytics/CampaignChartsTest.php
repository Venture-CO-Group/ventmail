<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\PdfRenderer;
use App\Modules\Analytics\Models\AnalyticsReport;
use App\Modules\Analytics\Models\EngagementClientDaily;
use App\Modules\Analytics\Models\LinkClick;
use App\Modules\Analytics\Services\CampaignCharts;
use App\Modules\Analytics\Services\ClickMap;
use App\Modules\Analytics\Services\Reports\ReportDocumentBuilder;
use App\Modules\Analytics\Testing\FakePdfRenderer;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Campaign\Models\CampaignVariant;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Template\Services\TemplateVersions;
use App\Modules\Tenancy\Services\WorkspaceContext;
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

it('e-mail az URL útvonalában: rögzítéskor és olvasáskor is {redacted}; UI, CSV, /v1 és PDF tiszta (review S1)', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('owner');
    $campaign = AnalyticsFixtures::campaign();
    $map = app(ClickMap::class);

    // ÚJ kattintás: a normalizálás már redaktál, a kulcs stabil (két címzett = egy sor).
    $map->record((string) Str::uuid7(), (string) $campaign->uuid, null, 'https://shop.pelda.hu/u/kiss.janos@pelda.hu/ajanlat', 'kiss.janos@pelda.hu');
    $map->record((string) Str::uuid7(), (string) $campaign->uuid, null, 'https://shop.pelda.hu/u/nagy.eva%40pelda.hu/ajanlat', 'nagy.eva@pelda.hu');

    // RÉGI (a javítás előtt tárolt) sor nyers címmel — nem írjuk át, de olvasáskor redaktálódik.
    LinkClick::query()->insert([
        'workspace_id' => $workspace->getKey(), 'message_uuid' => (string) Str::uuid7(), 'campaign_uuid' => $campaign->uuid,
        'url_hash' => KeyedHash::of('click-url', 'https://shop.pelda.hu/u/regi.cim@pelda.hu/ajanlat'),
        'url' => 'https://shop.pelda.hu/u/regi.cim@pelda.hu/ajanlat', 'recipient_hash' => 'x', 'clicks' => 1, 'unique_clicks' => 1,
        'first_clicked_at' => now(), 'last_clicked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(ClickMap::linkKey('https://shop.pelda.hu/u/a@b.hu/ajanlat'))->toBe(ClickMap::linkKey('https://shop.pelda.hu/u/c%40d.hu/ajanlat'));

    $links = $map->forCampaign($campaign);
    expect($links)->toHaveCount(1)
        ->and($links[0]['url'])->toBe('https://shop.pelda.hu/u/{redacted}/ajanlat')
        ->and($links[0]['unique_clicks'])->toBe(3);

    $session = ['workspace_uuid' => $workspace->uuid];
    $csv = (string) $this->actingAs($user)->withSession($session)->get('/analytics/campaigns/'.$campaign->uuid.'/links.csv')->getContent();
    expect($csv)->not->toContain('@')->not->toContain('%40');

    ['plain' => $key] = ApiKey::issue('Riport', ['analytics:read']);
    $v1 = (string) $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/v1/analytics/campaigns/'.$campaign->uuid)->assertOk()->getContent();
    expect($v1)->not->toContain('pelda.hu\/ajanlat"')->not->toContain('kiss.janos')->not->toContain('regi.cim');

    $this->actingAs($user)->withSession($session)->get('/analytics?campaign='.$campaign->uuid)
        ->assertInertia(fn (AssertableInertia $page) => $page->loadDeferredProps('campaign', fn (AssertableInertia $reload) => $reload->where('campaignSection.links.0.url', 'https://shop.pelda.hu/u/{redacted}/ajanlat')));

    // A PDF top-link táblájában sincs e-mail.
    $renderer = new FakePdfRenderer;
    app()->instance(PdfRenderer::class, $renderer);
    $report = new AnalyticsReport;
    $report->forceFill(['requested_by' => $user->getKey(), 'kind' => 'campaign', 'campaign_uuid' => $campaign->uuid, 'timezone' => 'UTC', 'locale' => 'hu', 'sections' => ['top_links'], 'params_hash' => str_repeat('f', 64), 'status' => 'queued'])->save();
    $html = app(ReportDocumentBuilder::class)->build($report, $workspace, false)->html;
    expect($html)->toContain('{redacted}')->not->toMatch('/[A-Za-z0-9._%+-]+(@|%40)pelda\.hu/');
});

it('őszi óraátállás: a megismételt óra egy kulcs, nincs duplikált címke (D268)', function () {
    [$workspace] = AnalyticsFixtures::tenant();
    // 2026-10-25 03:00 CEST → 02:00 CET (Europe/Budapest).
    $campaign = AnalyticsFixtures::campaign(['started_at' => Carbon::parse('2026-10-24 22:00:00', 'UTC')]);
    $scope = 'campaign:'.$campaign->uuid;

    StudioFixtures::engagement((int) $workspace->getKey(), 'a@pelda.hu', Carbon::parse('2026-10-25 00:30:00', 'UTC'), null, $scope); // 02:30 CEST
    StudioFixtures::engagement((int) $workspace->getKey(), 'b@pelda.hu', Carbon::parse('2026-10-25 01:30:00', 'UTC'), null, $scope); // 02:30 CET

    $points = app(CampaignCharts::class)->timeline($campaign, 'Europe/Budapest')['points'];
    $keys = array_column($points, 'at');

    expect($keys)->toBe(array_values(array_unique($keys)))
        ->and(collect($points)->firstWhere('at', '2026-10-25T02:00')['opens'])->toBe(2);
});

it('nem UUID-alakú kampány/journey paraméter 404, nem 500', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->get('/analytics?campaign=nem-uuid')->assertNotFound();
    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->get('/analytics?journey=1%27%20or%201=1')->assertNotFound();
    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->get('/analytics/campaigns/nem-uuid')->assertNotFound();
});

it('GDPR: a törlési kérés és a retenciós összevonás után az e-mailes útvonal sem marad a táblában (D282)', function () {
    [$workspace] = AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign();

    foreach (['erintett@pelda.hu' => 'https://shop.pelda.hu/u/erintett@pelda.hu/ajanlat', 'masik@pelda.hu' => 'https://shop.pelda.hu/u/masik%40pelda.hu/x'] as $recipient => $url) {
        // A javítás ELŐTT tárolt alak: nyers útvonal (a mai `record()` már redaktálna).
        LinkClick::query()->insert([
            'workspace_id' => $workspace->getKey(), 'message_uuid' => (string) Str::uuid7(), 'campaign_uuid' => $campaign->uuid,
            'url_hash' => KeyedHash::of('click-url', $url), 'url' => $url, 'recipient_hash' => KeyedHash::of('email', $recipient),
            'clicks' => 1, 'unique_clicks' => 1, 'first_clicked_at' => now()->subDays(200), 'last_clicked_at' => now()->subDays(200),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    app(WorkspaceContext::class)->forget();
    $this->artisan('logs:redact-subject', ['email' => 'erintett@pelda.hu'])->assertSuccessful();
    $this->artisan('analytics:purge-click-map')->assertSuccessful();

    app(WorkspaceContext::class)->set($workspace);
    $urls = LinkClick::query()->pluck('url')->all();

    expect($urls)->not->toBeEmpty();
    foreach ($urls as $url) {
        expect($url)->not->toContain('@')->not->toContain('%40');
    }
});

it('a tárolt cím redaktálása fail-closed: @, %40, %2540, és a nem értelmezhető alak is (N6)', function () {
    expect(ClickMap::redactStored('https://a.hu/u/x%2540y.hu/z'))->toBe('https://a.hu/u/{redacted}/z')
        ->and(ClickMap::redactStored('https://a.hu/u/X%40Y.HU'))->toBe('https://a.hu/u/{redacted}')
        ->and(ClickMap::redactStored('nem url de@cim.hu'))->toBe('{redacted}')
        ->and(ClickMap::redactStored('https://a.hu/akcio?utm_source=x'))->toBe('https://a.hu/akcio?utm_source=x')
        ->and(ClickMap::redactStored(ClickMap::OVERFLOW_URL))->toBe(ClickMap::OVERFLOW_URL);
});
