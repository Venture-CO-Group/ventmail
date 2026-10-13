<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Contracts\PdfRenderer;
use App\Modules\Analytics\Contracts\ReportArtifactStore;
use App\Modules\Analytics\Models\AnalyticsReport;
use App\Modules\Analytics\Services\Pdf\PdfRenderFailed;
use App\Modules\Analytics\Services\Reports\ReportDocumentBuilder;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A PDF-RIPORT renderelése (v1.1 · 8. modul) a `reports` sorban (saját supervisor, D121 minta).
 *
 * - A tenant-kontextust a riport-sor workspace-éből állítja (RefreshCampaignStats minta), és a
 *   végén visszaállítja: a worker következő jobja tiszta kontextust kap.
 * - Ugyanazok a szolgáltatások, mint a képernyőn; a nyelv és az időzóna a kérő beállítása, a
 *   soron rögzítve (a riport nem változik attól, hogy a kérő később nyelvet vált).
 * - Hiba → `failed` + gépi `failure_code`, nem újrapróbált végtelen ciklus (tries = 2).
 * - Kész → a PDF a privát tárolóba, `ready`, lejárat +7 nap.
 */
final class RenderAnalyticsReport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 120;

    public function __construct(
        public readonly string $reportUuid,
        public readonly int $workspaceId,
    ) {
        $this->onQueue((string) config('analytics.reports.queue', 'reports'));
        $this->onConnection(config('analytics.reports.connection'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->reportUuid;
    }

    public function handle(WorkspaceContext $context, ReportDocumentBuilder $builder, PdfRenderer $renderer, ReportArtifactStore $store): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;
        $previousLocale = App::getLocale();
        $context->set($workspace);

        try {
            $report = AnalyticsReport::query()->where('uuid', $this->reportUuid)->first();

            if ($report === null || ! in_array($report->status, ['queued', 'rendering'], true)) {
                return;
            }

            $report->forceFill(['status' => 'rendering', 'started_at' => now(), 'failure_code' => null])->save();
            App::setLocale((string) $report->locale);

            try {
                $document = $builder->build($report, $workspace, config('analytics.pdf.renderer') === 'gotenberg');
                $pdf = $renderer->render($document);
                $path = $store->put((string) $workspace->uuid, (string) $report->uuid, $pdf->bytes);
            } catch (PdfRenderFailed $e) {
                $this->fail($report, $e->failureCode);

                return;
            } catch (ModelNotFoundException) {
                $this->fail($report, 'campaign_missing');

                return;
            }

            $report->forceFill([
                'status' => 'ready',
                'file_path' => $path,
                'file_size' => strlen($pdf->bytes),
                'page_count' => $pdf->pageCount,
                'sha256' => $pdf->sha256,
                'finished_at' => now(),
                'expires_at' => now()->addDays((int) config('analytics.reports.ttl_days', 7)),
            ])->save();
        } finally {
            App::setLocale($previousLocale);
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    /** A végleges hiba (a próbálkozások elfogytak): a sor ne ragadjon `rendering`-ben. */
    public function failed(?Throwable $exception): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $context = app(WorkspaceContext::class);
        $previous = $context->hasWorkspace() ? $context->workspace() : null;
        $context->set($workspace);

        try {
            $report = AnalyticsReport::query()->where('uuid', $this->reportUuid)->first();

            if ($report !== null && in_array($report->status, ['queued', 'rendering'], true)) {
                $this->fail($report, 'render_failed');
            }

            Log::warning('A PDF-riport renderelése elbukott', ['report' => $this->reportUuid, 'exception' => $exception !== null ? $exception::class : null]);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    private function fail(AnalyticsReport $report, string $code): void
    {
        $report->forceFill(['status' => 'failed', 'failure_code' => mb_substr($code, 0, 32), 'finished_at' => now()])->save();
    }
}
