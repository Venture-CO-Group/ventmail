<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\PdfRenderer;
use App\Modules\Analytics\Data\ReportDocument;
use App\Modules\Analytics\Providers\AnalyticsServiceProvider;
use App\Modules\Analytics\Services\Pdf\GotenbergPdfRenderer;
use App\Modules\Analytics\Services\Pdf\PdfRenderFailed;
use App\Modules\Analytics\Services\Pdf\PdfTooLarge;
use App\Modules\Analytics\Testing\FakePdfRenderer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 | v1.1 · 8. modul — a Gotenberg-kliens (Http::fake): multipart-mezők, időkorlát, hibakódok,
 | méretkorlát, és hogy CSAK belső, engedélyezett hosztra küld tenant-adatot.
 */

function vmGotenberg(string $url = 'http://gotenberg:3000', int $maxPdf = 20_000_000): GotenbergPdfRenderer
{
    return new GotenbergPdfRenderer($url, 60, 5_000_000, $maxPdf, ['gotenberg']);
}

function vmDoc(): ReportDocument
{
    return new ReportDocument('<html><body>riport</body></html>', '<html><body><span class="pageNumber"></span></body></html>', ['report.js' => 'x', 'report.css' => 'y', 'logo.svg' => '<svg/>']);
}

it('a convert/html végpontra multiparttal küld, a pinelt verzió mezőneveivel', function () {
    Http::fake(['gotenberg:3000/*' => Http::response(FakePdfRenderer::minimalPdf(3), 200, ['Content-Type' => 'application/pdf'])]);

    $pdf = vmGotenberg()->render(vmDoc());

    expect($pdf->pageCount)->toBe(3)->and($pdf->bytes)->toStartWith('%PDF-');

    Http::assertSent(function (Request $request): bool {
        $parts = collect($request->data())->mapWithKeys(fn (array $part) => [($part['filename'] ?? $part['name']) => (string) $part['contents']]);

        return $request->url() === 'http://gotenberg:3000/forms/chromium/convert/html'
            && $request->isMultipart()
            && $parts['index.html'] === '<html><body>riport</body></html>'
            && str_contains($parts['footer.html'], 'pageNumber')
            && $parts['report.js'] === 'x' && $parts['logo.svg'] === '<svg/>'
            && $parts['paperWidth'] === '8.27' && $parts['paperHeight'] === '11.7'
            && $parts['marginTop'] === '0.6' && $parts['marginLeft'] === '0.5'
            && $parts['printBackground'] === 'true'
            && $parts['preferCssPageSize'] === 'false'
            && $parts['emulatedMediaType'] === 'print'
            && $parts['waitForExpression'] === 'window.__vmReportReady === true'
            && $parts['failOnConsoleExceptions'] === 'true';
    });
});

it('a hibás válasz gépi kódot ad', function (callable $fake, string $code) {
    $fake();

    expect(fn () => vmGotenberg()->render(vmDoc()))->toThrow(fn (PdfRenderFailed $e) => expect($e->failureCode)->toBe($code));
})->with([
    'nem 200 (409, konzol-kivétel)' => [fn () => Http::fake(['*' => Http::response('conflict', 409)]), 'render_failed'],
    '503 (Gotenberg-időkorlát)' => [fn () => Http::fake(['*' => Http::response('deadline', 503)]), 'render_timeout'],
    'kapcsolati időtúllépés' => [fn () => Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds')), 'render_timeout'],
    'elérhetetlen' => [fn () => Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect')), 'renderer_unreachable'],
    'nem PDF a válasz' => [fn () => Http::fake(['*' => Http::response('<html>nem pdf</html>', 200)]), 'render_failed'],
]);

it('túl nagy kimenet → too_large', function () {
    Http::fake(['*' => Http::response(FakePdfRenderer::minimalPdf(1), 200)]);

    expect(fn () => vmGotenberg(maxPdf: 100)->render(vmDoc()))->toThrow(PdfTooLarge::class);
});

it('túl nagy bemenet → too_large, kérés nélkül', function () {
    Http::fake();

    expect(fn () => vmGotenberg()->render(new ReportDocument(str_repeat('a', 5_000_001), '')))
        ->toThrow(fn (PdfRenderFailed $e) => expect($e->failureCode)->toBe('too_large'));
    Http::assertNothingSent();
});

it('csak engedélyezett belső hosztra küld (SSRF-őr)', function (string $url) {
    Http::fake();

    expect(fn () => vmGotenberg($url)->render(vmDoc()))->toThrow(fn (PdfRenderFailed $e) => expect($e->failureCode)->toBe('renderer_misconfigured'));
    Http::assertNothingSent();
})->with(['https://gotenberg.evil.example', 'ftp://gotenberg:3000', 'http://user:pw@gotenberg:3000', 'http://gotenberg:3000/?x=1']);

it('a config alapértéke tesztben fake, élesben gotenberg', function () {
    expect(config('analytics.pdf.renderer'))->toBe('fake')
        ->and(configFileWith('analytics', ['APP_ENV' => 'production', 'ANALYTICS_PDF_RENDERER' => null])['pdf']['renderer'])->toBe('gotenberg')
        ->and(configFileWith('analytics', ['APP_ENV' => 'local', 'ANALYTICS_PDF_RENDERER' => null])['pdf']['renderer'])->toBe('fake');
});

it('fail-closed renderer-választás: ismeretlen érték hiba, élesben a fake tiltott (D263)', function () {
    config(['analytics.pdf.renderer' => 'gotenbreg']);
    expect(fn () => AnalyticsServiceProvider::renderer())->toThrow(InvalidArgumentException::class);

    config(['analytics.pdf.renderer' => 'fake']);
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => AnalyticsServiceProvider::renderer())->toThrow(RuntimeException::class, 'tiltott');

        config(['analytics.pdf.renderer' => 'gotenberg']);
        expect(AnalyticsServiceProvider::renderer())->toBe('gotenberg');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    // Az üres env-érték (`ANALYTICS_PDF_RENDERER=` a mintából) az alapértelmezés, nem „üres renderer".
    expect(configFileWith('analytics', ['APP_ENV' => 'production', 'ANALYTICS_PDF_RENDERER' => ''])['pdf']['renderer'])->toBe('gotenberg');
});

it('a render-smoke kiírja a renderert, és élesben a fake-kel bukik', function () {
    config(['analytics.pdf.renderer' => 'fake']);
    app()->instance(PdfRenderer::class, new FakePdfRenderer(record: false));

    $this->artisan('analytics:render-smoke')->expectsOutputToContain('renderer: FakePdfRenderer (fake)')->assertSuccessful();

    app()->detectEnvironment(fn () => 'production');

    try {
        $this->artisan('analytics:render-smoke')->assertFailed();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('a nem-tesztbeli fake nem gyűjti a dokumentumokat (hosszan futó worker)', function () {
    $fake = new FakePdfRenderer(record: false);
    $fake->render(vmDoc());
    $fake->render(vmDoc());

    expect($fake->rendered)->toBe([]);
});
