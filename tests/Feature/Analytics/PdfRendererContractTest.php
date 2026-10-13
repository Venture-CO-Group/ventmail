<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\PdfRenderer;
use App\Modules\Analytics\Data\ReportDocument;
use App\Modules\Analytics\Http\Controllers\DevSeedAnalyticsController;
use App\Modules\Analytics\Models\AnalyticsReport;
use App\Modules\Analytics\Models\WorkspaceBranding;
use App\Modules\Analytics\Services\BrandingService;
use App\Modules\Analytics\Services\Pdf\GotenbergPdfRenderer;
use App\Modules\Analytics\Services\Pdf\PdfRenderFailed;
use App\Modules\Analytics\Services\Reports\ReportDocumentBuilder;
use App\Modules\Analytics\Testing\FakePdfRenderer;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AnalyticsFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 8. modul — KÖZÖS KONTRAKTUS a fake-re és a valódi Gotenbergre (D7, D237; review RS3/S2).
 |
 | A dokumentum a VALÓDI riport-lánc kimenete (`ReportDocumentBuilder` + `analytics::report.document`
 | + a hálózat nélküli riport-csomag), minden szekcióval: kampányriport (idősor, kliens-kör, top
 | linkek, variánsok, kézbesíthetőség panasz-küszöb markLine-nal, bounce-kör) és időszaki riport
 | (vegyes oszlop+vonal növekedés, engagement-kör, journey-tábla), SAJÁT logóval és brand-színnel.
 |
 | A valódi ág `@group gotenberg`: a CI `gotenberg-contract` jobja és lokálban
 | `deploy/tests/gotenberg-up.sh && GOTENBERG_URL=http://127.0.0.1:3000 php artisan test --group=gotenberg`
 | — a deploy/compose.render.yml-lel AZONOS image, flagek és hardening (belső háló, read-only, tmpfs).
 | A `waitForExpression` + `failOnConsoleExceptions` bizonyítja, hogy a riport-JS minden grafikont
 | hálózat nélkül, hiba nélkül kirajzol.
 */

/** @return list<ReportDocument> a seedelt demó-tenant kampány- és időszaki riportja, minden szekcióval */
function vmContractDocuments(bool $strictAssets): array
{
    Storage::fake('local');
    config(['identity.dev_login_enabled' => true]);

    [$workspace, $user] = AnalyticsFixtures::tenant();
    app(DevSeedAnalyticsController::class)(app(WorkspaceContext::class));

    $image = imagecreatetruecolor(160, 80);
    imagefill($image, 0, 0, imagecolorallocate($image, 20, 40, 160));
    ob_start();
    imagepng($image);
    $logo = (string) ob_get_clean();

    WorkspaceBranding::query()->create([
        'primary_color' => '#0E1322', 'company_name' => 'Kontraktus Kft.', 'footer_text' => 'Kontraktus · Bizalmas',
        'logo_path' => app(BrandingService::class)->storeLogo($workspace, $logo, 'png'), 'logo_mime' => 'image/png', 'logo_width' => 160, 'logo_height' => 80,
    ]);

    $campaign = Campaign::query()->where('name', DevSeedAnalyticsController::CAMPAIGN_NAME)->sole();
    $documents = [];

    foreach ([
        ['kind' => 'campaign', 'campaign_uuid' => $campaign->uuid],
        ['kind' => 'period', 'period_from' => now()->subDays(29)->toDateString(), 'period_to' => now()->toDateString()],
    ] as $attributes) {
        $report = new AnalyticsReport;
        $report->forceFill(array_merge([
            'requested_by' => $user->getKey(), 'timezone' => 'Europe/Budapest', 'locale' => 'hu',
            'sections' => AnalyticsReport::SECTIONS, 'params_hash' => hash('sha256', (string) random_int(1, PHP_INT_MAX)), 'status' => 'rendering',
        ], $attributes))->save();

        $documents[] = app(ReportDocumentBuilder::class)->build($report, $workspace, $strictAssets);
    }

    return $documents;
}

function vmContract(PdfRenderer $renderer): void
{
    [$campaignDoc, $periodDoc] = vmContractDocuments($renderer instanceof GotenbergPdfRenderer);

    // A fixtúra tényleg mindent tartalmaz, amit a riport-JS kirajzol.
    expect($campaignDoc->html)->toContain('data-chart="timeline"')->toContain('data-chart="clients"')
        ->toContain('data-chart="deliverability"')->toContain('data-chart="bounces"')
        ->toContain('"threshold":{"value":0.3')->toContain('src="logo.png"')->toContain('--vm-brand: #0E1322')
        ->and($periodDoc->html)->toContain('data-chart="growth"')->toContain('"line":true')->toContain('data-chart="engagement"')
        ->and($campaignDoc->assets)->toHaveKey('logo.png');

    foreach ([$campaignDoc, $periodDoc] as $document) {
        $pdf = $renderer->render($document);

        expect($pdf->bytes)->toStartWith('%PDF-')
            ->and($pdf->sha256)->toBe(hash('sha256', $pdf->bytes))
            ->and($pdf->pageCount)->toBeGreaterThanOrEqual(2);
    }

    // A méretkorlát a bemenetre ugyanaz.
    expect(fn () => $renderer->render(new ReportDocument(str_repeat('a', 5 * 1024 * 1024 + 1), '')))
        ->toThrow(fn (PdfRenderFailed $e) => expect($e->failureCode)->toBe('too_large'));
}

it('a fake teljesíti a kontraktust', function () {
    vmContract(new FakePdfRenderer);
});

it('a valódi Gotenberg teljesíti a kontraktust', function () {
    $url = (string) (getenv('GOTENBERG_URL') ?: 'http://127.0.0.1:3000');
    $host = (string) parse_url($url, PHP_URL_HOST);

    vmContract(new GotenbergPdfRenderer($url, 60, 5 * 1024 * 1024, 20 * 1024 * 1024, [$host]));
})->group('gotenberg');
