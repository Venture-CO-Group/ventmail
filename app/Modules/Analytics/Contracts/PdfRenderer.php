<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Contracts;

use App\Modules\Analytics\Data\RenderedPdf;
use App\Modules\Analytics\Data\ReportDocument;
use App\Modules\Analytics\Services\Pdf\PdfRenderFailed;

/**
 * HTML → PDF (v1.1 · 8. modul, D7). Élesben a Gotenberg sidecar (MIT, headless Chromium, belső
 * háló), tesztben és fejlesztésben a `FakePdfRenderer`. A kettő UGYANAZT a kontraktust teljesíti
 * (`PdfRendererContractTest`).
 */
interface PdfRenderer
{
    /** @throws PdfRenderFailed */
    public function render(ReportDocument $document): RenderedPdf;
}
