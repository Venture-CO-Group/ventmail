<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services\Pdf;

use App\Modules\Analytics\Contracts\PdfRenderer;
use App\Modules\Analytics\Data\RenderedPdf;
use App\Modules\Analytics\Data\ReportDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * GOTENBERG SIDECAR (MIT, headless Chromium) — `POST /forms/chromium/convert/html` (D7, D238).
 *
 * - Csak a configban megadott, BELSŐ hosztra küld (SSRF- és adatszivárgás-őr: tenant-adat nem
 *   mehet egy elgépelt külső URL-re). A sidecar egy `internal: true` hálón él, kimenő forgalom
 *   nélkül; a Chromium csak `file:///tmp/…`-t tölthet (`--chromium-allow-list`).
 * - Minden erőforrás (riport-JS, CSS, logó) a multipart-kérésben megy, relatív hivatkozással —
 *   a riport-oldal hálózat nélkül renderel (D239).
 * - `waitForExpression`: a riport-JS a grafikonok kirajzolása után állítja `__vmReportReady`-t;
 *   `failOnConsoleExceptions`: egy JS-hiba nem ad csendben grafikon nélküli PDF-et.
 *
 * Mezőnevek a pinelt 8.37 verzió szerint (deploy/compose.render.yml).
 */
final class GotenbergPdfRenderer implements PdfRenderer
{
    public function __construct(
        private readonly string $url,
        private readonly int $timeout,
        private readonly int $maxHtmlBytes,
        private readonly int $maxPdfBytes,
        /** @var list<string> */
        private readonly array $allowedHosts,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('analytics.pdf.gotenberg_url'),
            (int) config('analytics.pdf.timeout', 60),
            (int) config('analytics.pdf.max_html_bytes'),
            (int) config('analytics.pdf.max_pdf_bytes'),
            (array) config('analytics.pdf.allowed_hosts', []),
        );
    }

    public function render(ReportDocument $document): RenderedPdf
    {
        $endpoint = $this->endpoint();

        $inputBytes = strlen($document->html) + strlen($document->footerHtml) + array_sum(array_map('strlen', $document->assets));

        if ($inputBytes > $this->maxHtmlBytes) {
            throw new PdfTooLarge('A riport bemenete túl nagy.');
        }

        $request = Http::timeout($this->timeout)
            ->connectTimeout(5)
            ->asMultipart()
            ->attach('files', $document->html, 'index.html', ['Content-Type' => 'text/html'])
            ->attach('files', $document->footerHtml, 'footer.html', ['Content-Type' => 'text/html']);

        foreach ($document->assets as $name => $bytes) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $name) !== 1 || in_array($name, ['index.html', 'footer.html'], true)) {
                throw new PdfRenderFailed('render_failed', 'Érvénytelen csatolmány-név.');
            }

            $request = $request->attach('files', $bytes, $name);
        }

        try {
            $response = $request->post($endpoint, [
                'paperWidth' => (string) $document->paper['width'],
                'paperHeight' => (string) $document->paper['height'],
                'marginTop' => (string) $document->margins['top'],
                'marginBottom' => (string) $document->margins['bottom'],
                'marginLeft' => (string) $document->margins['left'],
                'marginRight' => (string) $document->margins['right'],
                'printBackground' => 'true',
                'preferCssPageSize' => 'false',
                'emulatedMediaType' => 'print',
                'waitForExpression' => 'window.__vmReportReady === true',
                'failOnConsoleExceptions' => 'true',
            ]);
        } catch (ConnectionException $e) {
            throw new PdfRenderFailed(str_contains(strtolower($e->getMessage()), 'timed out') ? 'render_timeout' : 'renderer_unreachable');
        }

        if ($response->status() === 503) {
            throw new PdfRenderFailed('render_timeout');
        }

        if ($response->status() !== 200) {
            throw new PdfRenderFailed('render_failed', 'Gotenberg HTTP '.$response->status());
        }

        $bytes = $response->body();

        if (strlen($bytes) > $this->maxPdfBytes) {
            throw new PdfTooLarge('A kész PDF túl nagy.');
        }

        if (! str_starts_with($bytes, '%PDF-')) {
            throw new PdfRenderFailed('render_failed', 'A válasz nem PDF.');
        }

        return new RenderedPdf($bytes, RenderedPdf::countPages($bytes));
    }

    private function endpoint(): string
    {
        $parts = parse_url($this->url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! in_array($host, array_map('strtolower', $this->allowedHosts), true)
            || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new PdfRenderFailed('renderer_misconfigured', 'A Gotenberg URL nem engedélyezett belső hoszt.');
        }

        return rtrim($this->url, '/').'/forms/chromium/convert/html';
    }
}
