<?php

declare(strict_types=1);

namespace App\Modules\Template\Services\Assets;

use App\Modules\Template\Contracts\AssetStore;
use App\Modules\Template\Models\TemplateAsset;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * KÉPFELTÖLTÉS a szerkesztőből — az ELLENŐRZÉS itt, a tárolás az `AssetStore` mögött.
 *
 * ## A MIME a TARTALOMBÓL jön, nem a kliens fejlécéből
 *
 * A `Content-Type` és a kiterjesztés a feltöltő kezében van. Itt a bájtok döntenek
 * (`finfo` + `getimagesizefromstring`), és a kettőnek EGYEZNIE kell. SVG nem engedett: az
 * SVG szkriptet hordozhat, és a nyilvános URL a mi originünkön (vagy a ventVaulton) él.
 *
 * ## Méret és képméret
 *
 * A bájt-korlát a levél letöltési idejét védi, a képméret-korlát a dekompressziós bombát
 * (egy 50 KB-os PNG is lehet 30 000 × 30 000 pixel).
 */
final class AssetUploader
{
    /** @var array<string, string> MIME → kiterjesztés */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly AssetStore $store,
        private readonly WorkspaceContext $context,
        private readonly ImageInspector $inspector,
    ) {}

    public function upload(UploadedFile $file): TemplateAsset
    {
        $max = (int) config('template.assets.max_bytes');

        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > $max) {
            throw ValidationException::withMessages(['file' => __('ventmail.editor.assets.tooLarge', ['max' => (int) round($max / 1024 / 1024)])]);
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $limit = (int) config('template.assets.max_dimension');

        // A KÖZÖS ellenőrző lánc (v1.1 · 8. modul: `ImageInspector`, a workspace-logó is ezt használja).
        try {
            $image = $this->inspector->inspect($contents, self::ALLOWED, $limit);
            $mime = $image->mime;
            [$width, $height] = [$image->width, $image->height];

            // ÚJRAKÓDOLÁS metaadat nélkül (SS5): az EXIF (GPS, készülék, név) különben a
            // publikus URL-en bárkinek olvasható lenne.
            $contents = $this->inspector->reencode($contents, $mime);
        } catch (ImageRejected $rejected) {
            throw ValidationException::withMessages(['file' => match ($rejected->reason) {
                'dimensions' => __('ventmail.editor.assets.badDimensions', ['max' => $limit]),
                'processing' => __('ventmail.editor.assets.processing'),
                default => __('ventmail.editor.assets.badType'),
            }]);
        }

        $workspace = $this->context->workspace();
        $quota = (int) config('template.assets.workspace_quota_bytes');

        /*
         | KVÓTA ZÁRRAL (S1): a „összeg → írás → sor" lánc zár nélkül versenyezhet — két
         | párhuzamos feltöltés mindkettője a régi összeget látja, és együtt túllépik a
         | kvótát. A workspace-zár alatt a lánc soros; ha a sor írása elbukik, a már
         | kiírt fájl is törlődik (nem marad nyilvántartás nélküli, publikus fájl).
         */
        try {
            return $this->locked($contents, $mime, $quota, $workspace, $width, $height, $file);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['file' => __('ventmail.editor.assets.busy')]);
        }
    }

    private function locked(string $contents, string $mime, int $quota, Workspace $workspace, int $width, int $height, UploadedFile $file): TemplateAsset
    {
        return Cache::lock('template-assets:'.$workspace->getKey(), 30)->block((int) config('template.assets.lock_wait_seconds', 10), function () use ($contents, $mime, $quota, $workspace, $width, $height, $file): TemplateAsset {
            $used = (int) TemplateAsset::query()->sum('size');

            if ($used + strlen($contents) > $quota) {
                throw ValidationException::withMessages(['file' => __('ventmail.editor.assets.quota', ['max' => (int) round($quota / 1024 / 1024)])]);
            }

            $stored = $this->store->put((string) $workspace->uuid, $contents, self::ALLOWED[$mime]);

            try {
                return TemplateAsset::query()->create([
                    'driver' => $this->store->name(),
                    'path' => $stored->path,
                    'url' => $stored->url,
                    'mime' => $mime,
                    'size' => strlen($contents),
                    'width' => $width,
                    'height' => $height,
                    // A FELTÖLTŐ fájlneve csak címke (a tárolt név véletlen UUID); vezérlőkarakter nélkül.
                    'original_name' => mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/u', '', $file->getClientOriginalName()), 0, 255),
                    'created_by' => Auth::id(),
                ]);
            } catch (Throwable $e) {
                $this->store->delete($stored->path);

                throw $e;
            }
        });
    }
}
