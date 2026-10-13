<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\PdfRenderer;
use App\Modules\Analytics\Contracts\ReportArtifactStore;
use App\Modules\Analytics\Jobs\RenderAnalyticsReport;
use App\Modules\Analytics\Models\AnalyticsReport;
use App\Modules\Analytics\Models\WorkspaceBranding;
use App\Modules\Analytics\Services\BrandingService;
use App\Modules\Analytics\Services\Reports\ReportDocumentBuilder;
use App\Modules\Analytics\Testing\FakePdfRenderer;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AnalyticsFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 8. modul — a riport-JOB a fake rendererrel: tenant-kontextus, hálózat nélküli HTML,
 | escape, branding + fallback, A4, lábléc-oldalszám, szekciók, hibakódok, lejárat, privát diszk.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->renderer = new FakePdfRenderer;
    app()->instance(PdfRenderer::class, $this->renderer);
});

function vmQueuedReport($user, array $attributes): AnalyticsReport
{
    $report = new AnalyticsReport;
    $report->forceFill(array_merge([
        'requested_by' => $user->getKey(), 'timezone' => 'Europe/Budapest', 'locale' => 'hu',
        'sections' => ['kpis', 'charts', 'top_links', 'journeys', 'deliverability'],
        'params_hash' => hash('sha256', (string) random_int(1, PHP_INT_MAX)), 'status' => 'queued',
    ], $attributes))->save();

    return $report;
}

function vmRun(AnalyticsReport $report): AnalyticsReport
{
    $workspaceId = (int) $report->workspace_id;
    app(WorkspaceContext::class)->forget();
    (new RenderAnalyticsReport((string) $report->uuid, $workspaceId))->handle(app(WorkspaceContext::class), app(ReportDocumentBuilder::class), app(PdfRenderer::class), app(ReportArtifactStore::class));

    expect(app(WorkspaceContext::class)->hasWorkspace())->toBeFalse();

    $workspace = Workspace::query()->findOrFail($workspaceId);
    app(WorkspaceContext::class)->set($workspace);

    return AnalyticsReport::query()->where('uuid', $report->uuid)->sole();
}

it('kampányriport: kész, privát diszken, 7 napos lejárat, a kontextus visszaáll', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');
    $campaign = AnalyticsFixtures::campaign(['name' => 'Őszi <b>újdonság</b>']);
    $report = vmQueuedReport($user, ['kind' => 'campaign', 'campaign_uuid' => $campaign->uuid]);

    $done = vmRun($report);

    expect($done->status)->toBe('ready')
        ->and($done->file_path)->toBe('reports/'.$workspace->uuid.'/'.$report->uuid.'.pdf')
        ->and($done->page_count)->toBe(2)
        ->and($done->sha256)->toHaveLength(64)
        ->and($done->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()))->toBeTrue();

    Storage::disk('local')->assertExists($done->file_path);
    expect(Storage::disk('local')->get($done->file_path))->toStartWith('%PDF-');
});

it('a HTML hálózat nélküli, escape-elt, A4, oldalszámos láblécű', function () {
    [, $user] = AnalyticsFixtures::tenant('analyst');
    $campaign = AnalyticsFixtures::campaign(['name' => '</script><img src=x onerror=alert(1)>']);
    vmRun(vmQueuedReport($user, ['kind' => 'campaign', 'campaign_uuid' => $campaign->uuid]));

    $doc = $this->renderer->last();

    expect($doc)->not->toBeNull()
        // Egyetlen külső erőforrás sincs: semmi http(s):// a dokumentumban és a lábléc-HTML-ben.
        ->and($doc->html)->not->toMatch('#(src|href)=["\']?https?://#i')
        ->and($doc->html)->not->toContain('fonts.googleapis')
        ->and($doc->html)->toContain('src="report.js"')->toContain('href="report.css"')
        ->and($doc->html)->toContain("default-src 'none'; script-src file:")
        // XSS: a tenant-szöveg escape-elve, a JSON-blokkból sem tör ki.
        ->and($doc->html)->not->toContain('<img src=x onerror')
        ->and($doc->html)->toContain('&lt;/script&gt;&lt;img src=x onerror=alert(1)&gt;')
        ->and(substr_count($doc->html, '</script>'))->toBe(2)
        ->and($doc->paper)->toBe(['width' => 8.27, 'height' => 11.7])
        ->and($doc->margins)->toBe(['top' => 0.6, 'bottom' => 0.6, 'left' => 0.5, 'right' => 0.5])
        ->and($doc->footerHtml)->toContain('<span class="pageNumber"></span> / <span class="totalPages"></span>')
        ->and(array_keys($doc->assets))->toBe(['report.js', 'report.css', 'logo.svg']);
});

it('branding: saját logó és szín a borítón, a lábléc a saját szöveg; nincs → Venture-alapértelmezés', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign();

    vmRun(vmQueuedReport($user, ['kind' => 'campaign', 'campaign_uuid' => $campaign->uuid]));
    $default = $this->renderer->last();
    expect($default->html)->not->toContain('--vm-brand')->toContain('vm-report--cover-default')
        ->and($default->assets)->toHaveKey('logo.svg');

    $image = imagecreatetruecolor(120, 80);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    $path = app(BrandingService::class)->storeLogo($workspace, $png, 'png');
    WorkspaceBranding::query()->create(['primary_color' => '#0E1322', 'company_name' => 'Nádor & Társa', 'footer_text' => 'Bizalmas <i>', 'logo_path' => $path, 'logo_mime' => 'image/png', 'logo_width' => 120, 'logo_height' => 80]);

    vmRun(vmQueuedReport($user, ['kind' => 'campaign', 'campaign_uuid' => $campaign->uuid]));
    $branded = $this->renderer->last();

    expect($branded->html)->toContain('style="--vm-brand: #0E1322"')
        ->toContain('vm-report--cover-light')
        ->toContain('Nádor &amp; Társa')
        ->toContain('src="logo.png"')
        ->and($branded->assets['logo.png'])->toBe($png)
        ->and($branded->footerHtml)->toContain('Bizalmas &lt;i&gt;');
});

it('időszaki riport: csak a kért szekciók; kampánylista, journey és bounce táblák', function () {
    [, $user] = AnalyticsFixtures::tenant();
    AnalyticsFixtures::campaign(['name' => 'Időszakos kampány']);

    vmRun(vmQueuedReport($user, ['kind' => 'period', 'period_from' => now()->subDays(7)->toDateString(), 'period_to' => now()->toDateString(), 'sections' => ['kpis', 'deliverability']]));
    $doc = $this->renderer->last();

    expect($doc->html)->toContain('Időszakos kampány')
        ->toContain('Bounce-típusok')
        ->not->toContain('data-chart=')
        ->and($doc->html)->toContain('vm-report__kpis');
});

it('renderelési hiba és túl nagy kimenet → failed + gépi kód', function (string $code) {
    [, $user] = AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign();
    $this->renderer->failWith($code);

    $done = vmRun(vmQueuedReport($user, ['kind' => 'campaign', 'campaign_uuid' => $campaign->uuid]));

    expect($done->status)->toBe('failed')
        ->and($done->failure_code)->toBe($code)
        ->and($done->file_path)->toBeNull();
})->with(['render_timeout', 'too_large']);

it('a kérő nyelvén készül (a soron rögzített locale)', function () {
    [, $user] = AnalyticsFixtures::tenant();
    $campaign = AnalyticsFixtures::campaign();

    vmRun(vmQueuedReport($user, ['kind' => 'campaign', 'campaign_uuid' => $campaign->uuid, 'locale' => 'de']));

    expect($this->renderer->last()->html)->toContain('lang="de"')->toContain('Kampagnenbericht');
});

it('a végleges hiba (failed) a sort failed-re állítja, a kontextust nem hagyja bent', function () {
    [, $user] = AnalyticsFixtures::tenant();
    $report = vmQueuedReport($user, ['kind' => 'period', 'period_from' => now()->subDay()->toDateString(), 'period_to' => now()->toDateString(), 'status' => 'rendering']);
    $workspaceId = (int) $report->workspace_id;
    app(WorkspaceContext::class)->forget();

    (new RenderAnalyticsReport((string) $report->uuid, $workspaceId))->failed(new RuntimeException('chromium'));

    expect(app(WorkspaceContext::class)->hasWorkspace())->toBeFalse();
    app(WorkspaceContext::class)->set(Workspace::query()->findOrFail($workspaceId));
    expect(AnalyticsReport::query()->where('uuid', $report->uuid)->sole()->failure_code)->toBe('render_failed');
});
