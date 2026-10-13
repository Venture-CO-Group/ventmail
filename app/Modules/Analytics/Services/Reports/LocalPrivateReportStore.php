<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services\Reports;

use App\Modules\Analytics\Contracts\ReportArtifactStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A riport-PDF a PRIVÁT diszken (D235), a D227 megosztott volume-on: a worker írja, az app
 * szolgálja ki (aláírt, hitelesített letöltés). Nyilvános URL nincs.
 *
 * Útvonal: `reports/{workspace_uuid}/{report_uuid}.pdf` — csak UUID-kból, a hívó szövege nem
 * kerül bele (útvonal-bejárás kizárva).
 *
 * // TODO ventVault: a ventVault fájltár élesítésekor `VentVaultReportStore` váltja (HUMAN-QUEUE).
 */
final class LocalPrivateReportStore implements ReportArtifactStore
{
    public function put(string $workspaceUuid, string $reportUuid, string $bytes): string
    {
        if (! Str::isUuid($workspaceUuid) || ! Str::isUuid($reportUuid)) {
            throw new InvalidArgumentException('Érvénytelen riport-azonosító.');
        }

        $path = sprintf('reports/%s/%s.pdf', strtolower($workspaceUuid), strtolower($reportUuid));

        if ($this->disk()->put($path, $bytes, ['visibility' => 'private']) === false) {
            throw new \RuntimeException('A riport nem írható a tárolóba.');
        }

        return $path;
    }

    public function exists(string $path): bool
    {
        return self::ours($path) && $this->disk()->exists($path);
    }

    public function readStream(string $path)
    {
        return self::ours($path) ? $this->disk()->readStream($path) : null;
    }

    public function size(string $path): int
    {
        return self::ours($path) ? (int) $this->disk()->size($path) : 0;
    }

    public function delete(string $path): void
    {
        if (self::ours($path)) {
            $this->disk()->delete($path);
        }
    }

    /** Csak a saját előtagunk alatti, UUID-alakú útvonal (egy hamisított útvonal ne törölhessen). */
    private static function ours(string $path): bool
    {
        return preg_match('#^reports/[0-9a-f-]{36}/[0-9a-f-]{36}\.pdf$#', $path) === 1;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('analytics.reports.disk', 'local'));
    }
}
