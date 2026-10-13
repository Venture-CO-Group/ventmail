<?php

declare(strict_types=1);

use App\Modules\Analytics\Console\RenderSmokeCommand;
use App\Modules\Analytics\Contracts\PdfRenderer;
use App\Modules\Analytics\Data\ReportDocument;
use App\Modules\Analytics\Services\Pdf\GotenbergPdfRenderer;
use App\Modules\Analytics\Services\Pdf\PdfRenderFailed;
use App\Modules\Analytics\Services\Reports\ReportAssets;
use App\Modules\Analytics\Testing\FakePdfRenderer;

/*
 | v1.1 · 8. modul — KÖZÖS KONTRAKTUS a fake-re és a valódi Gotenbergre (D7, D237).
 |
 | A valódi ág `@group gotenberg`: a CI `gotenberg-contract` jobja (a pinelt image service-ként)
 | és lokálban `GOTENBERG_URL=http://127.0.0.1:3000 php artisan test --group=gotenberg` futtatja.
 | A fixtúra-riport ECharts-grafikonokat tartalmaz: a `waitForExpression` + `failOnConsoleExceptions`
 | bizonyítja, hogy a riport-JS hálózat nélkül, hiba nélkül kirajzol.
 */

function vmContract(PdfRenderer $renderer): void
{
    $pdf = $renderer->render(RenderSmokeCommand::fixture(app(ReportAssets::class), $renderer instanceof GotenbergPdfRenderer));

    expect($pdf->bytes)->toStartWith('%PDF-')
        ->and($pdf->sha256)->toBe(hash('sha256', $pdf->bytes))
        ->and($pdf->pageCount)->toBeGreaterThanOrEqual(2);

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
