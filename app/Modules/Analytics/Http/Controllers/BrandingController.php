<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Data\BrandColor;
use App\Modules\Analytics\Models\WorkspaceBranding;
use App\Modules\Analytics\Services\BrandingService;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Template\Services\Assets\ImageInspector;
use App\Modules\Template\Services\Assets\ImageRejected;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * WORKSPACE-BRANDING (v1.1 · 8. modul, D233, D236) — a PDF-riport borítója és lábléce.
 *
 * - Írás: `workspace.manage` (owner, admin); a logó olvasása `analytics.view` (a riport-előnézet).
 * - Logó: PNG / JPEG / WebP, ≤ 1 MB, 64–2000 px, képarány ≤ 8 : 1; SVG elutasítva. A közös
 *   `ImageInspector` lánc (MIME a tartalomból, bomba-korlátok, GD-újrakódolás EXIF nélkül).
 *   Privát diszken, nyilvános URL nélkül; kiszolgálás hitelesítve, `nosniff`, `private`.
 * - Szín: `#RRGGBB`, a `BrandColor` WCAG-döntésével (olvashatatlan borító-szín elutasítva).
 * - Szöveg: vezérlőkarakter tiltva, vágott, hosszkorlátos.
 * - Minden írás nevesített audit-sor: `branding.updated`, `branding.logo_uploaded`,
 *   `branding.logo_removed`.
 */
final class BrandingController
{
    private const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(
        private readonly BrandingService $branding,
        private readonly AuditLogger $audit,
    ) {}

    public function show(WorkspaceContext $context): Response
    {
        Gate::authorize('workspace.manage');

        $row = $this->branding->current();

        return Inertia::render('Analytics/Branding', [
            'branding' => [
                'primaryColor' => $row?->primary_color,
                'companyName' => $row?->company_name,
                'footerText' => $row?->footer_text,
                'logo' => $row?->hasLogo() ? ['width' => $row->logo_width, 'height' => $row->logo_height, 'size' => $row->logo_size] : null,
            ],
            'preview' => AnalyticsUiController::brandingPreview($this->branding, $context),
            'workspaceName' => (string) $context->workspace()->name,
            'limits' => [
                'maxBytes' => (int) config('analytics.branding.logo_max_bytes'),
                'minPx' => (int) config('analytics.branding.logo_min_px'),
                'maxPx' => (int) config('analytics.branding.logo_max_px'),
                'maxRatio' => (int) config('analytics.branding.logo_max_ratio'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('workspace.manage');

        $input = [
            'primary_color' => self::clean($request->input('primary_color')),
            'company_name' => self::clean($request->input('company_name')),
            'footer_text' => self::clean($request->input('footer_text')),
        ];

        $validated = validator($input, [
            'primary_color' => ['nullable', 'string', 'regex:'.BrandColor::PATTERN],
            'company_name' => ['nullable', 'string', 'max:120'],
            'footer_text' => ['nullable', 'string', 'max:300'],
        ], [
            'primary_color.regex' => __('ventmail.branding.validation.color'),
        ])->validate();

        foreach (['company_name', 'footer_text'] as $field) {
            // Vezérlőkarakter, és az irány-felülíró (bidi override / isolate) karakterek is: a PDF
            // borítóján és láblécén egy RLO a szöveget megfordíthatja (review N3, D270).
            if (is_string($request->input($field)) && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', (string) $request->input($field)) === 1) {
                throw ValidationException::withMessages([$field => __('ventmail.branding.validation.control')]);
            }
        }

        if ($validated['primary_color'] !== null && ! BrandColor::from((string) $validated['primary_color'])->isUsable()) {
            throw ValidationException::withMessages(['primary_color' => __('ventmail.branding.validation.contrast')]);
        }

        $row = $this->branding->current() ?? new WorkspaceBranding;
        $before = $row->exists ? $row->only(['primary_color', 'company_name', 'footer_text']) : [];

        $row->fill([
            'primary_color' => $validated['primary_color'] !== null ? strtoupper((string) $validated['primary_color']) : null,
            'company_name' => $validated['company_name'],
            'footer_text' => $validated['footer_text'],
            'updated_by' => $request->user()?->getKey(),
        ])->save();

        $this->audit->log('branding.updated', $row, before: $before, after: $row->only(['primary_color', 'company_name', 'footer_text']));

        return back()->with('success', __('ventmail.branding.saved'));
    }

    public function uploadLogo(Request $request, ImageInspector $inspector, WorkspaceContext $context): RedirectResponse
    {
        Gate::authorize('workspace.manage');

        $file = $request->file('logo');
        $max = (int) config('analytics.branding.logo_max_bytes');

        if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() === false || $file->getSize() > $max) {
            throw ValidationException::withMessages(['logo' => __('ventmail.branding.validation.logoSize', ['max' => (int) round($max / 1024 / 1024)])]);
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $minPx = (int) config('analytics.branding.logo_min_px');
        $maxPx = (int) config('analytics.branding.logo_max_px');

        try {
            $image = $inspector->inspect($contents, self::LOGO_TYPES, $maxPx, $minPx);

            $ratio = max($image->width, $image->height) / max(1, min($image->width, $image->height));

            if ($ratio > (int) config('analytics.branding.logo_max_ratio')) {
                throw ValidationException::withMessages(['logo' => __('ventmail.branding.validation.logoRatio', ['ratio' => (int) config('analytics.branding.logo_max_ratio')])]);
            }

            // ÚJRAKÓDOLÁS metaadat nélkül: a polyglot-fájl (kép + rejtett hasznos teher) és az EXIF
            // nem éli túl, a tárolt bájt csak pixeladat.
            $bytes = $inspector->reencode($contents, $image->mime);
        } catch (ImageRejected $rejected) {
            throw ValidationException::withMessages(['logo' => match ($rejected->reason) {
                'dimensions' => __('ventmail.branding.validation.logoDimensions', ['min' => $minPx, 'max' => $maxPx]),
                'processing' => __('ventmail.branding.validation.processing'),
                default => __('ventmail.branding.validation.logoType'),
            }]);
        }

        $row = $this->branding->current() ?? new WorkspaceBranding;
        $previousPath = $row->logo_path;
        $path = $this->branding->storeLogo($context->workspace(), $bytes, $image->extension);

        $row->fill([
            'logo_path' => $path,
            'logo_mime' => $image->mime,
            'logo_width' => $image->width,
            'logo_height' => $image->height,
            'logo_size' => strlen($bytes),
            'logo_sha256' => hash('sha256', $bytes),
            'updated_by' => $request->user()?->getKey(),
        ])->save();

        if ($previousPath !== null && $previousPath !== $path) {
            $this->branding->deleteLogo($previousPath);
        }

        $this->audit->log('branding.logo_uploaded', $row, after: [
            'logo_mime' => $image->mime, 'logo_width' => $image->width, 'logo_height' => $image->height, 'logo_size' => strlen($bytes),
        ]);

        return back()->with('success', __('ventmail.branding.logoSaved'));
    }

    public function removeLogo(Request $request): RedirectResponse
    {
        Gate::authorize('workspace.manage');

        $row = $this->branding->current();

        if ($row !== null && $row->hasLogo()) {
            $before = $row->only(['logo_mime', 'logo_width', 'logo_height', 'logo_size']);
            $this->branding->deleteLogo($row->logo_path);

            $row->fill([
                'logo_path' => null, 'logo_mime' => null, 'logo_width' => null, 'logo_height' => null,
                'logo_size' => null, 'logo_sha256' => null, 'updated_by' => $request->user()?->getKey(),
            ])->save();

            $this->audit->log('branding.logo_removed', $row, before: $before);
        }

        return back()->with('success', __('ventmail.branding.logoRemoved'));
    }

    /** A logó a saját workspace-éből (global scope); nincs → 404. */
    public function logo(): HttpResponse
    {
        Gate::authorize('analytics.view');

        $row = $this->branding->current();
        $bytes = $row !== null ? $this->branding->logoBytes($row) : null;

        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => (string) $row?->logo_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => 'inline; filename="logo"',
        ]);
    }

    /** Vágás; az üres szöveg `null` (= Venture-alapértelmezés). */
    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
