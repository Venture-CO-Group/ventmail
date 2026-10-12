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
    /** A progresszív JPEG scan-jeinek felső száma (S2) — egy valódi kép 10 körül van. */
    private const MAX_JPEG_SCANS = 100;

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
    ) {}

    public function upload(UploadedFile $file): TemplateAsset
    {
        $max = (int) config('template.assets.max_bytes');

        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > $max) {
            throw ValidationException::withMessages(['file' => __('ventmail.editor.assets.tooLarge', ['max' => (int) round($max / 1024 / 1024)])]);
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $info = @getimagesizefromstring($contents);

        if (! isset(self::ALLOWED[$mime]) || $info === false || ($info['mime'] ?? null) !== $mime) {
            throw ValidationException::withMessages(['file' => __('ventmail.editor.assets.badType')]);
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $limit = (int) config('template.assets.max_dimension');

        if ($width < 1 || $height < 1 || $width > $limit || $height > $limit) {
            throw ValidationException::withMessages(['file' => __('ventmail.editor.assets.badDimensions', ['max' => $limit])]);
        }

        // PROGRESSZÍV JPEG CPU-bomba (S2): a dekódolás ideje a scan-ek számával nő. A
        // scan-fejléc (SOS, `FF DA`) az entrópia-kódolt adatban nem fordulhat elő (ott a
        // `FF` bájt `FF 00`-ként töltődik), tehát a puszta számlálás pontos.
        if ($mime === 'image/jpeg' && substr_count($contents, "\xFF\xDA") > self::MAX_JPEG_SCANS) {
            throw ValidationException::withMessages(['file' => __('ventmail.editor.assets.badType')]);
        }

        // ÚJRAKÓDOLÁS metaadat nélkül (SS5): az EXIF (GPS, készülék, név) különben a
        // publikus URL-en bárkinek olvasható lenne.
        $contents = $this->reencode($contents, $mime);

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

    /**
     * A kép ÚJRAKÓDOLÁSA GD-vel: a kimenet csak pixeladat, metaadat (EXIF, XMP, ICC-
     * megjegyzés) nélkül. A GIF-et nem kódoljuk újra (az animáció a GD-n elveszne), hanem a
     * blokkjait szűrjük: a komment- és a nem-animációs alkalmazás-kiterjesztés (XMP) kiesik
     * (`GifMetadata`).
     *
     * GD nélkül a feltöltés ELUTASUL (fail-closed): metaadatos képet nem teszünk publikussá.
     */
    private function reencode(string $contents, string $mime): string
    {
        if ($mime === 'image/gif') {
            try {
                return GifMetadata::strip($contents);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['file' => __('ventmail.editor.assets.badType')]);
            }
        }

        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages(['file' => __('ventmail.editor.assets.processing')]);
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            throw ValidationException::withMessages(['file' => __('ventmail.editor.assets.badType')]);
        }

        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        ob_start();

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 88),
            'image/png' => imagepng($image, null, 9),
            'image/webp' => imagewebp($image, null, 88),
            default => false,
        };

        $out = (string) ob_get_clean();

        if (! $ok || $out === '') {
            throw ValidationException::withMessages(['file' => __('ventmail.editor.assets.processing')]);
        }

        return $out;
    }
}
