<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\ReportArtifactStore;
use App\Modules\Analytics\Models\AnalyticsReport;
use App\Modules\Analytics\Testing\FakePdfRenderer;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\AnalyticsFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 8. modul (D240) — a letöltés: aláírt (10 perces) + hitelesített + saját workspace;
 | lejárt riport 410; `attachment`, `private, no-store`, `nosniff`; auditált.
 */

beforeEach(fn () => Storage::fake('local'));

function vmReadyReport($workspace, $user, array $attributes = []): AnalyticsReport
{
    $uuid = (string) Str::uuid();
    $path = app(ReportArtifactStore::class)->put((string) $workspace->uuid, $uuid, FakePdfRenderer::minimalPdf(2));

    $report = new AnalyticsReport;
    $report->forceFill(array_merge([
        'uuid' => $uuid, 'requested_by' => $user->getKey(), 'kind' => 'period', 'timezone' => 'UTC', 'locale' => 'hu',
        'sections' => ['kpis'], 'params_hash' => str_repeat('c', 64), 'status' => 'ready', 'file_path' => $path,
        'file_size' => strlen(FakePdfRenderer::minimalPdf(2)), 'expires_at' => now()->addDays(7),
    ], $attributes))->save();

    return $report;
}

it('aláírt linkkel letölthető: PDF, attachment, no-store, nosniff, audit', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');
    $report = vmReadyReport($workspace, $user);
    $url = URL::temporarySignedRoute('analytics.reports.download', now()->addMinutes(10), ['uuid' => $report->uuid]);

    $response = $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->get($url)->assertOk();

    expect($response->streamedContent())->toStartWith('%PDF-')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(AuditLog::query()->where('action', 'analytics.report_downloaded')->count())->toBe(1);
});

it('aláírás nélkül vagy lejárt aláírással 403', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');
    $report = vmReadyReport($workspace, $user);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/analytics/reports/'.$report->uuid.'/download')->assertForbidden();

    $expired = URL::temporarySignedRoute('analytics.reports.download', now()->subMinute(), ['uuid' => $report->uuid]);
    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->get($expired)->assertForbidden();
});

it('idegen workspace riportja érvényes aláírással is 404', function () {
    [$foreignWs, $foreignUser] = AnalyticsFixtures::tenant('owner');
    $report = vmReadyReport($foreignWs, $foreignUser);
    $url = URL::temporarySignedRoute('analytics.reports.download', now()->addMinutes(10), ['uuid' => $report->uuid]);

    [$workspace, $user] = AnalyticsFixtures::tenant('owner');

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->get($url)->assertNotFound();
    expect(AuditLog::query()->where('action', 'analytics.report_downloaded')->count())->toBe(0);
});

it('lejárt riport 410', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');
    $report = vmReadyReport($workspace, $user, ['status' => 'expired', 'file_path' => null]);
    $url = URL::temporarySignedRoute('analytics.reports.download', now()->addMinutes(10), ['uuid' => $report->uuid]);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->get($url)->assertStatus(410);

    $late = vmReadyReport($workspace, $user, ['expires_at' => now()->subMinute(), 'params_hash' => str_repeat('d', 64)]);
    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get(URL::temporarySignedRoute('analytics.reports.download', now()->addMinutes(10), ['uuid' => $late->uuid]))->assertStatus(410);
});
