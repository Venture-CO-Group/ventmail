<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Console;

use App\Modules\Analytics\Contracts\PdfRenderer;
use App\Modules\Analytics\Data\ReportDocument;
use App\Modules\Analytics\Services\Pdf\PdfRenderFailed;
use App\Modules\Analytics\Services\Reports\ReportAssets;
use Illuminate\Console\Command;

/**
 * ÉLES ELLENŐRZÉS (v1.1 · 8. modul): egy FIXTÚRA-riport (tenant-adat nélkül) renderelése a
 * beállított rendererrel — a deploy után bizonyítja, hogy a Gotenberg elérhető, a riport-csomag
 * az image-ben van, és az ECharts hálózat nélkül kirajzol (`waitForExpression`). A PDF-et nem
 * tárolja; a méretet és az oldalszámot írja ki.
 */
final class RenderSmokeCommand extends Command
{
    protected $signature = 'analytics:render-smoke';

    protected $description = 'Fixtúra PDF-riport renderelése tenant-adat nélkül (éles ellenőrzés).';

    public function handle(PdfRenderer $renderer, ReportAssets $assets): int
    {
        try {
            $pdf = $renderer->render(self::fixture($assets, config('analytics.pdf.renderer') === 'gotenberg'));
        } catch (PdfRenderFailed $e) {
            $this->error('A renderelés sikertelen: '.$e->failureCode);

            return self::FAILURE;
        }

        if (! str_starts_with($pdf->bytes, '%PDF-')) {
            $this->error('A válasz nem PDF.');

            return self::FAILURE;
        }

        $this->info(sprintf('OK: %d bájt, %s oldal, sha256 %s', strlen($pdf->bytes), $pdf->pageCount ?? '?', $pdf->sha256));

        return self::SUCCESS;
    }

    /** Kétoldalas fixtúra-dokumentum egy vonal-, egy oszlop- és egy kördiagrammal. */
    public static function fixture(ReportAssets $assets, bool $strict): ReportDocument
    {
        $bundle = $assets->load($strict);
        $labels = array_map(static fn (int $d): string => sprintf('2026-01-%02d', $d), range(1, 14));
        $charts = [
            'timeline' => ['title' => 'Line', 'type' => 'line', 'labels' => $labels, 'series' => [
                ['name' => 'A', 'data' => range(1, 14), 'token' => '--vt-color-accent-500'],
                ['name' => 'B', 'data' => array_reverse(range(1, 14)), 'token' => '--vt-color-cyan-500'],
            ]],
            'growth' => ['title' => 'Bar', 'type' => 'bar', 'labels' => $labels, 'series' => [
                ['name' => 'A', 'data' => range(1, 14), 'token' => '--vt-color-accent-500', 'stack' => 'g'],
                ['name' => 'B', 'data' => array_map(static fn (int $n): int => -$n, range(1, 14)), 'token' => '--vt-color-danger', 'stack' => 'g'],
            ]],
            'bounces' => ['title' => 'Pie', 'type' => 'pie', 'items' => [['name' => 'A', 'value' => 3], ['name' => 'B', 'value' => 2]]],
        ];

        $sections = '';

        foreach (array_keys($charts) as $key) {
            $sections .= '<section class="vm-report__section vm-report__keep"><h2>'.$key.'</h2><div class="vm-report__chart" data-chart="'.$key.'"></div></section>';
        }

        $json = (string) json_encode(['charts' => $charts], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $html = '<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8">'
            .'<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src file:; style-src file: \'unsafe-inline\'; img-src file: data:; font-src file:">'
            .'<link rel="stylesheet" href="report.css"><title>smoke</title></head><body class="vm-report vm-report--cover-default">'
            .'<section class="vm-report__cover"><div class="vm-report__band"><h1 class="vm-report__title">ventMail render smoke</h1></div></section>'
            .$sections
            .'<script type="application/json" id="vm-report-data">'.$json.'</script><script src="report.js"></script></body></html>';
        $footer = '<!doctype html><html><head><meta charset="utf-8"><style>'.str_replace('</', '<\/', $bundle['css']).'</style></head>'
            .'<body class="vm-report-footer"><div class="vm-report-footer__row"><span>smoke</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div></body></html>';

        return new ReportDocument($html, $footer, ['report.js' => $bundle['js'], 'report.css' => $bundle['css'], 'logo.svg' => $assets->defaultLogo()]);
    }
}
