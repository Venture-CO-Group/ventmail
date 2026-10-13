<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Contracts\ReportArtifactStore;
use App\Modules\Analytics\Data\AnalyticsRange;
use App\Modules\Analytics\Jobs\RenderAnalyticsReport;
use App\Modules\Analytics\Models\AnalyticsReport;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\WorkspaceTimezone;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BRANDELT PDF-RIPORT: kérés, lista, letöltés (v1.1 · 8. modul).
 *
 * - KÉRÉS: `analytics.view` + throttle (workspace 10/óra, felhasználó 3/perc); időszak legfeljebb
 *   366 nap, vége legfeljebb ma; idegen kampány 404; ugyanaz a kérő + paraméter egy várakozó /
 *   renderelő riportot tarthat (részleges egyedi index) → a meglévő sort kapja vissza.
 * - LISTA: JSON, a letöltő-link KÉRÉSRE generált, 10 percig érvényes aláírt URL (D240) — nem
 *   tárolt, nem naplózódik hosszú életű link.
 * - LETÖLTÉS: hitelesített + workspace + aláírt + `analytics.view` + saját workspace (különben
 *   404); `attachment`, `private, no-store`, `nosniff`; auditált; lejárt riport 410.
 */
final class ReportController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, WorkspaceContext $context): JsonResponse
    {
        Gate::authorize('analytics.view');

        $validated = $request->validate([
            'kind' => ['required', Rule::in(['campaign', 'period'])],
            'campaignUuid' => ['required_if:kind,campaign', 'nullable', 'uuid'],
            'from' => ['required_if:kind,period', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:kind,period', 'nullable', 'date_format:Y-m-d'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', Rule::in(AnalyticsReport::SECTIONS)],
        ]);

        $user = $request->user();
        $timezone = WorkspaceTimezone::for($context->workspace(), $user);
        $sections = array_values(array_intersect(AnalyticsReport::SECTIONS, $validated['sections']));
        $campaignUuid = null;
        $from = null;
        $to = null;

        if ($validated['kind'] === 'campaign') {
            // IDEGEN kampány 404 (a global scope a saját workspace-re szűr).
            $campaignUuid = (string) Campaign::query()->where('uuid', (string) $validated['campaignUuid'])->firstOrFail()->uuid;
        } else {
            if (! AnalyticsRange::validCustom($validated['from'] ?? null, $validated['to'] ?? null, $timezone)) {
                throw ValidationException::withMessages(['from' => __('ventmail.analytics.report.invalidPeriod', ['days' => AnalyticsRange::MAX_DAYS])]);
            }

            [$from, $to] = [(string) $validated['from'], (string) $validated['to']];
        }

        // A riport NYELVE a kérőé (a profil-beállítás), különben a kérés nyelve — a soron rögzítve.
        $supported = ['hu', 'en', 'de', 'fr', 'it', 'es', 'ru'];
        $locale = in_array((string) $user?->locale, $supported, true) ? (string) $user?->locale
            : (in_array(App::getLocale(), $supported, true) ? App::getLocale() : 'hu');
        $params = ['kind' => $validated['kind'], 'campaign' => $campaignUuid, 'from' => $from, 'to' => $to, 'sections' => $sections, 'tz' => $timezone, 'locale' => $locale];
        $hash = hash('sha256', (string) json_encode($params));

        $existing = $this->inflight($user?->getKey(), $hash);

        if ($existing !== null) {
            return response()->json(['report' => $this->present($existing), 'deduplicated' => true], 200);
        }

        try {
            $report = DB::transaction(function () use ($validated, $user, $campaignUuid, $from, $to, $timezone, $locale, $sections, $hash): AnalyticsReport {
                $report = AnalyticsReport::query()->create([
                    'requested_by' => $user?->getKey(),
                    'kind' => $validated['kind'],
                    'campaign_uuid' => $campaignUuid,
                    'period_from' => $from,
                    'period_to' => $to,
                    'timezone' => $timezone,
                    'locale' => $locale,
                    'sections' => $sections,
                    'params_hash' => $hash,
                    'status' => 'queued',
                ]);

                $this->audit->log('analytics.report_requested', $report, after: [
                    'kind' => $report->kind, 'campaign_uuid' => $campaignUuid, 'period_from' => $from, 'period_to' => $to, 'sections' => $sections,
                ]);

                return $report;
            });
        } catch (QueryException $e) {
            // Verseny: egy párhuzamos kérés már beszúrta ugyanazt (részleges egyedi index).
            if (($existing = $this->inflight($user?->getKey(), $hash)) !== null) {
                return response()->json(['report' => $this->present($existing), 'deduplicated' => true], 200);
            }

            throw $e;
        }

        RenderAnalyticsReport::dispatch((string) $report->uuid, (int) $report->workspace_id);

        return response()->json(['report' => $this->present($report->fresh() ?? $report), 'deduplicated' => false], 202);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('analytics.view');

        $reports = AnalyticsReport::query()
            ->where('requested_by', $request->user()?->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json(['reports' => $reports->map(fn (AnalyticsReport $report): array => $this->present($report))->all()]);
    }

    public function download(Request $request, string $uuid, ReportArtifactStore $store): Response
    {
        Gate::authorize('analytics.view');

        // SAJÁT workspace (global scope) — idegen riport 404, nem 403 (a létezése sem szivárog).
        $report = AnalyticsReport::query()->where('uuid', $uuid)->firstOrFail();

        if ($report->status === 'expired' || ($report->expires_at !== null && $report->expires_at->isPast())) {
            abort(410);
        }

        if ($report->status !== 'ready' || $report->file_path === null || ! $store->exists((string) $report->file_path)) {
            abort(404);
        }

        $this->audit->log('analytics.report_downloaded', $report, context: ['size' => $report->file_size]);

        $path = (string) $report->file_path;
        $name = 'ventmail-report-'.($report->kind === 'campaign' ? 'campaign' : 'period').'-'.$report->created_at?->format('Ymd').'.pdf';

        return new StreamedResponse(function () use ($store, $path): void {
            $stream = $store->readStream($path);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Content-Length' => (string) $store->size($path),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function inflight(mixed $userId, string $hash): ?AnalyticsReport
    {
        return AnalyticsReport::query()
            ->where('requested_by', $userId)
            ->where('params_hash', $hash)
            ->whereIn('status', ['queued', 'rendering'])
            ->first();
    }

    /** @return array<string, mixed> */
    private function present(AnalyticsReport $report): array
    {
        return [
            'uuid' => (string) $report->uuid,
            'kind' => (string) $report->kind,
            'campaignUuid' => $report->campaign_uuid,
            'from' => $report->period_from?->toDateString(),
            'to' => $report->period_to?->toDateString(),
            'sections' => (array) $report->sections,
            'status' => (string) $report->status,
            'failureCode' => $report->failure_code,
            'size' => $report->file_size,
            'pages' => $report->page_count,
            'createdAt' => $report->created_at?->toIso8601String(),
            'expiresAt' => $report->expires_at?->toIso8601String(),
            // KÉRÉSRE generált, rövid életű aláírt link (D240) — csak kész, le nem járt riportra.
            'downloadUrl' => $report->isDownloadable()
                ? URL::temporarySignedRoute('analytics.reports.download', now()->addMinutes((int) config('analytics.reports.link_minutes', 10)), ['uuid' => $report->uuid])
                : null,
        ];
    }
}
