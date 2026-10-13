<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Analytics\Jobs\RenderAnalyticsReport;
use App\Modules\Analytics\Models\AnalyticsReport;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\AnalyticsFixtures;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 8. modul — PDF-riport KÉRÉSE: jogosultság, idegen kampány, időszak-korlát, throttle,
 | deduplikáció, audit, a `reports` sor.
 */

beforeEach(function () {
    Queue::fake();
    RateLimiter::clear('analytics-report');
});

function vmRequestReport(User $user, $workspace, array $payload)
{
    return test()->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->postJson('/analytics/reports', $payload);
}

it('az elemző kérhet kampányriportot: sor, audit, job a reports sorra', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');
    $campaign = AnalyticsFixtures::campaign();

    $response = vmRequestReport($user, $workspace, ['kind' => 'campaign', 'campaignUuid' => $campaign->uuid, 'sections' => ['kpis', 'charts', 'top_links']])
        ->assertStatus(202)
        ->assertJsonPath('report.status', 'queued')
        ->assertJsonPath('report.downloadUrl', null);

    $report = AnalyticsReport::query()->sole();
    expect($report->campaign_uuid)->toBe((string) $campaign->uuid)
        ->and($report->requested_by)->toBe($user->getKey())
        ->and($report->locale)->toBe('hu')
        ->and($report->params_hash)->toHaveLength(64)
        ->and($response->json('report.uuid'))->toBe((string) $report->uuid);

    Queue::assertPushedOn('reports', RenderAnalyticsReport::class, fn (RenderAnalyticsReport $job) => $job->reportUuid === (string) $report->uuid);

    expect(AuditLog::query()->where('action', 'analytics.report_requested')->where('workspace_id', $workspace->getKey())->exists())->toBeTrue();
});

it('ugyanaz a kérés a várakozó riportot adja vissza (dedup), más paraméter új sort', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');
    $campaign = AnalyticsFixtures::campaign();
    $payload = ['kind' => 'campaign', 'campaignUuid' => $campaign->uuid, 'sections' => ['kpis']];

    $first = vmRequestReport($user, $workspace, $payload)->assertStatus(202)->json('report.uuid');
    $second = vmRequestReport($user, $workspace, $payload)->assertOk()->assertJsonPath('deduplicated', true)->json('report.uuid');

    expect($second)->toBe($first)->and(AnalyticsReport::query()->count())->toBe(1);

    vmRequestReport($user, $workspace, [...$payload, 'sections' => ['kpis', 'charts']])->assertStatus(202);
    expect(AnalyticsReport::query()->count())->toBe(2);

    Queue::assertPushed(RenderAnalyticsReport::class, 2);
});

it('idegen kampány 404, sor nem keletkezik', function () {
    AnalyticsFixtures::tenant();
    $foreign = AnalyticsFixtures::campaign();
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');

    vmRequestReport($user, $workspace, ['kind' => 'campaign', 'campaignUuid' => $foreign->uuid, 'sections' => ['kpis']])->assertNotFound();

    expect(AnalyticsReport::query()->count())->toBe(0);
});

it('időszak: legfeljebb 366 nap, a vége legfeljebb ma; ismeretlen szekció 422', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');

    vmRequestReport($user, $workspace, ['kind' => 'period', 'from' => now()->subDays(400)->toDateString(), 'to' => now()->toDateString(), 'sections' => ['kpis']])
        ->assertStatus(422)->assertJsonValidationErrors('from');
    vmRequestReport($user, $workspace, ['kind' => 'period', 'from' => now()->toDateString(), 'to' => now()->addDay()->toDateString(), 'sections' => ['kpis']])
        ->assertStatus(422);
    vmRequestReport($user, $workspace, ['kind' => 'period', 'from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString(), 'sections' => ['kpis', 'html']])
        ->assertStatus(422)->assertJsonValidationErrors('sections.1');
    // A hibás kérés is fogyasztja a percenkénti keretet (3/perc) — a következő perc tiszta.
    $this->travel(61)->seconds();
    vmRequestReport($user, $workspace, ['kind' => 'period', 'from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString(), 'sections' => ['kpis', 'journeys']])
        ->assertStatus(202);
});

it('throttle: felhasználónként percenként 3 kérés', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');

    foreach (range(1, 3) as $i) {
        vmRequestReport($user, $workspace, ['kind' => 'period', 'from' => now()->subDays($i)->toDateString(), 'to' => now()->toDateString(), 'sections' => ['kpis']])->assertStatus(202);
    }

    vmRequestReport($user, $workspace, ['kind' => 'period', 'from' => now()->subDays(9)->toDateString(), 'to' => now()->toDateString(), 'sections' => ['kpis']])->assertStatus(429);
});

it('tagság nélküli felhasználó nem kérhet riportot', function () {
    [$workspace] = AnalyticsFixtures::tenant();
    $stranger = User::create(['sso_issuer' => 'https://sso.teszt.invalid', 'sso_subject' => 'idegen-'.uniqid(), 'name' => 'Idegen', 'email' => 'idegen@pelda.hu', 'locale' => 'hu']);

    $this->actingAs($stranger)->withSession(['workspace_uuid' => $workspace->uuid])
        ->postJson('/analytics/reports', ['kind' => 'period', 'from' => now()->subDays(3)->toDateString(), 'to' => now()->toDateString(), 'sections' => ['kpis']])
        ->assertForbidden();

    expect(AnalyticsReport::query()->count())->toBe(0);
});

it('a lista csak a kérő saját riportjait adja, a link kérésre generált aláírt URL', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');
    $other = StudioFixtures::user($workspace, 'analyst');

    $mine = AnalyticsReport::query()->create(['requested_by' => $user->getKey(), 'kind' => 'period', 'period_from' => now()->subDays(3), 'period_to' => now(), 'timezone' => 'UTC', 'locale' => 'hu', 'sections' => ['kpis'], 'params_hash' => str_repeat('a', 64), 'status' => 'ready', 'file_path' => 'reports/'.$workspace->uuid.'/'.Str::uuid().'.pdf', 'expires_at' => now()->addDay()]);
    AnalyticsReport::query()->create(['requested_by' => $other->getKey(), 'kind' => 'period', 'timezone' => 'UTC', 'locale' => 'hu', 'sections' => ['kpis'], 'params_hash' => str_repeat('b', 64), 'status' => 'queued']);

    $list = $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->getJson('/analytics/reports')->assertOk()->json('reports');

    expect($list)->toHaveCount(1)
        ->and($list[0]['uuid'])->toBe((string) $mine->uuid)
        ->and($list[0]['downloadUrl'])->toContain('/analytics/reports/'.$mine->uuid.'/download')->toContain('signature=')->toContain('expires=');
});
