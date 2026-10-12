<?php

declare(strict_types=1);

namespace App\Modules\Template\Services\Assets;

use App\Modules\Template\Contracts\AssetStore;
use App\Modules\Template\Data\StoredAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A `public` diszk (D10) — amíg a ventVault public-asset API-ja nem él.
 *
 * // TODO ventVault: a ventVault élesítésekor `VentVaultAssetStore` váltja (HUMAN-QUEUE).
 *
 * ## Tenant-izolált útvonal
 *
 * `templates/<workspace-uuid>/<yyyy>/<mm>/<uuid>.<ext>` — a fájlnév SOHA nem a feltöltőé
 * (útvonal-bejárás, felülírás, PII a fájlnévben), hanem egy véletlen UUID.
 *
 * ## Az URL `https://`
 *
 * A block-tree `src` mezője csak `https://` lehet (`UrlPolicy`). A nyilvános alap-URL a
 * `template.assets.public_url` (alapból `APP_URL/storage`, `https`-re emelve): egy
 * `http://` kép-URL-t a validátor elutasítana, és a levélben vegyes tartalom lenne.
 */
final class LocalPublicAssetStore implements AssetStore
{
    private const EXTENSIONS = ['jpg', 'png', 'gif', 'webp'];

    public function name(): string
    {
        return 'local';
    }

    public function put(string $workspaceUuid, string $contents, string $extension): StoredAsset
    {
        if (! Str::isUuid($workspaceUuid) || ! in_array($extension, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException('Érvénytelen workspace vagy kiterjesztés.');
        }

        $path = sprintf('templates/%s/%s/%s.%s', strtolower($workspaceUuid), now()->format('Y/m'), Str::uuid(), $extension);

        Storage::disk($this->disk())->put($path, $contents, ['visibility' => 'public']);

        return new StoredAsset($path, self::publicUrl($path));
    }

    public function delete(string $path): void
    {
        // CSAK a saját előtagunk alatt: egy hamisított útvonal (`../../.env`) ne töröljön.
        if (! str_starts_with($path, 'templates/') || str_contains($path, '..')) {
            return;
        }

        Storage::disk($this->disk())->delete($path);
    }

    public static function publicUrl(string $path): string
    {
        $base = rtrim((string) config('template.assets.public_url'), '/');

        // A SÉMA `https`: a `UrlPolicy` csak ezt engedi a levélben.
        $base = (string) preg_replace('#^http://#i', 'https://', $base);

        return $base.'/'.ltrim($path, '/');
    }

    private function disk(): string
    {
        return (string) config('template.assets.disk', 'public');
    }
}
