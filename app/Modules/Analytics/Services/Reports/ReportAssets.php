<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services\Reports;

use App\Modules\Analytics\Services\Pdf\PdfRenderFailed;

/**
 * A riport-oldal saját, HÁLÓZAT NÉLKÜLI csomagja (D239): `public/build/report/report.js` (ECharts
 * SVG-rendererrel, Vue nélkül, klasszikus — nem modul — szkript: a `file://` originről a modul-
 * szkriptet a Chromium CORS-hibával tiltja) és `report.css` (a `@venture/design` tokenjei +
 * riport-elrendezés). A külön Vite-build (`vite.report.config.ts`) a `npm run build` része; a
 * release-image buildje bukik nélküle (Dockerfile).
 *
 * Tesztben és a `fake` rendererrel a hiányzó csomag nem hiba (a PHP-tesztek frontend-build
 * nélkül futnak): egy csonk jelzi a kész állapotot. A valódi rendererrel hiányzó csomag =
 * `assets_missing` hiba, nem grafikon nélküli PDF.
 */
final class ReportAssets
{
    public const DIRECTORY = 'build/report';

    /** @return array{js: string, css: string} */
    public function load(bool $strict): array
    {
        $js = public_path(self::DIRECTORY.'/report.js');
        $css = public_path(self::DIRECTORY.'/report.css');

        if (is_file($js) && is_file($css)) {
            return ['js' => (string) file_get_contents($js), 'css' => (string) file_get_contents($css)];
        }

        if ($strict) {
            throw new PdfRenderFailed('assets_missing', 'A riport-csomag (public/build/report) hiányzik.');
        }

        return ['js' => 'window.__vmReportReady = true;', 'css' => ''];
    }

    /** A Venture-alapértelmezett logó (saját, megbízható SVG — a tenant SVG-t soha nem tölthet fel). */
    public function defaultLogo(): string
    {
        $path = public_path('brand/logomark.svg');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
