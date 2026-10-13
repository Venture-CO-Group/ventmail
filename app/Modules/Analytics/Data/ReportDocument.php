<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

/**
 * A renderelendő riport: a fő HTML, a lábléc-HTML (oldalszámmal), a mellé csatolt fájlok
 * (fájlnév → bájtok: `report.js`, `report.css`, `logo.png`) és a papír.
 */
final class ReportDocument
{
    /**
     * @param  array<string, string>  $assets
     * @param  array{width: float, height: float}  $paper  hüvelykben
     * @param  array{top: float, bottom: float, left: float, right: float}  $margins  hüvelykben
     */
    public function __construct(
        public readonly string $html,
        public readonly string $footerHtml,
        public readonly array $assets = [],
        public readonly array $paper = ['width' => 8.27, 'height' => 11.7],
        public readonly array $margins = ['top' => 0.6, 'bottom' => 0.6, 'left' => 0.5, 'right' => 0.5],
    ) {}
}
