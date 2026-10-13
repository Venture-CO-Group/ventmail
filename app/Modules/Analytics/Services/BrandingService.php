<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Data\BrandColor;
use App\Modules\Analytics\Models\WorkspaceBranding;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * A WORKSPACE-BRANDING feloldása (D233): a saját sor, vagy a Venture-alapértelmezés. A
 * sub-account NEM örököl (a parent brandingje nem szivároghat a child riportjába, és fordítva).
 *
 * A logó a PRIVÁT diszken (`branding/{workspace_uuid}/logo.{ext}`) — nyilvános URL nincs; a
 * felület a hitelesített `GET /settings/branding/logo`-n, a riport fájl-csatolmányként kapja.
 * // TODO ventVault: a ventVault fájltár élesítésekor oda költözik (HUMAN-QUEUE).
 */
final class BrandingService
{
    /** @return array{color: ?string, coverText: string, companyName: ?string, footerText: ?string, hasLogo: bool, logoMime: ?string, logoWidth: ?int, logoHeight: ?int, updatedAt: ?string} */
    public function resolve(?WorkspaceBranding $branding): array
    {
        $color = $branding !== null && BrandColor::isValid($branding->primary_color) ? BrandColor::from((string) $branding->primary_color) : null;

        return [
            'color' => $color?->hex,
            // Fallback: a Venture accent tokenen fehér helyett a döntést a CSS-oldal hozza
            // (`--vt-on-accent`); saját színnél a kontraszt-számítás.
            'coverText' => $color?->coverText() ?? 'default',
            'companyName' => $branding?->company_name,
            'footerText' => $branding?->footer_text,
            'hasLogo' => (bool) $branding?->hasLogo(),
            'logoMime' => $branding?->logo_mime,
            'logoWidth' => $branding?->logo_width,
            'logoHeight' => $branding?->logo_height,
            'updatedAt' => $branding?->updated_at?->toIso8601String(),
        ];
    }

    public function current(): ?WorkspaceBranding
    {
        return WorkspaceBranding::query()->first();
    }

    public function logoBytes(WorkspaceBranding $branding): ?string
    {
        if (! $branding->hasLogo() || ! self::ours((string) $branding->logo_path)) {
            return null;
        }

        $bytes = $this->disk()->get((string) $branding->logo_path);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    public function storeLogo(Workspace $workspace, string $bytes, string $extension): string
    {
        $path = sprintf('branding/%s/logo.%s', strtolower((string) $workspace->uuid), $extension);

        // Egy korábbi, MÁS kiterjesztésű logó ne maradjon árván.
        foreach (['png', 'jpg', 'webp'] as $other) {
            if ($other !== $extension) {
                $this->disk()->delete(sprintf('branding/%s/logo.%s', strtolower((string) $workspace->uuid), $other));
            }
        }

        if ($this->disk()->put($path, $bytes, ['visibility' => 'private']) === false) {
            throw new \RuntimeException('A logó nem írható a tárolóba.');
        }

        return $path;
    }

    public function deleteLogo(?string $path): void
    {
        if ($path !== null && self::ours($path)) {
            $this->disk()->delete($path);
        }
    }

    public static function ours(string $path): bool
    {
        return preg_match('#^branding/[0-9a-f-]{36}/logo\.(png|jpg|webp)$#', $path) === 1;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('analytics.branding.disk', 'local'));
    }
}
