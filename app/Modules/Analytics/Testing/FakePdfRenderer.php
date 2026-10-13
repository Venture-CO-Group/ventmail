<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Testing;

use App\Modules\Analytics\Contracts\PdfRenderer;
use App\Modules\Analytics\Data\RenderedPdf;
use App\Modules\Analytics\Data\ReportDocument;
use App\Modules\Analytics\Services\Pdf\PdfRenderFailed;
use App\Modules\Analytics\Services\Pdf\PdfTooLarge;

/**
 * DETERMINISZTIKUS PDF-renderer teszthez és fejlesztéshez (Chromium nélkül).
 *
 * Minimális, érvényes PDF-et ad (két oldal: borító + tartalom), és rögzíti az utolsó
 * `ReportDocument`-et, hogy a teszt a HTML-t, a láblécet és a csatolmányokat ellenőrizhesse. A
 * `failWith()` a hibautakat (időtúllépés, túl nagy) játssza le. A méretkorlát UGYANAZ, mint a
 * valódié — a közös kontraktus-teszt mindkettőn fut.
 */
final class FakePdfRenderer implements PdfRenderer
{
    /** @var list<ReportDocument> */
    public array $rendered = [];

    private ?string $failure = null;

    public function failWith(?string $code): self
    {
        $this->failure = $code;

        return $this;
    }

    public function last(): ?ReportDocument
    {
        return $this->rendered === [] ? null : $this->rendered[array_key_last($this->rendered)];
    }

    public function render(ReportDocument $document): RenderedPdf
    {
        $input = strlen($document->html) + strlen($document->footerHtml) + array_sum(array_map('strlen', $document->assets));

        if ($input > (int) config('analytics.pdf.max_html_bytes')) {
            throw new PdfTooLarge('A riport bemenete túl nagy.');
        }

        if ($this->failure !== null) {
            throw $this->failure === 'too_large' ? new PdfTooLarge : new PdfRenderFailed($this->failure);
        }

        $this->rendered[] = $document;

        return new RenderedPdf(self::minimalPdf(2), 2);
    }

    /** Egy minimális, szabványos PDF `$pages` üres A4-oldallal (determinisztikus bájtok). */
    public static function minimalPdf(int $pages): string
    {
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>'];
        $kids = implode(' ', array_map(static fn (int $i): string => (3 + $i).' 0 R', range(0, $pages - 1)));
        $objects[] = "<< /Type /Pages /Kids [{$kids}] /Count {$pages} >>";

        for ($i = 0; $i < $pages; $i++) {
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>';
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
