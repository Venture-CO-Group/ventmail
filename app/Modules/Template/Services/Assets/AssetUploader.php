<?php

declare(strict_types=1);

namespace App\Modules\Template\Services\Assets;

use App\Modules\Template\Contracts\AssetStore;
use App\Modules\Template\Models\TemplateAsset;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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

        $stored = $this->store->put((string) $this->context->workspace()->uuid, $contents, self::ALLOWED[$mime]);

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
    }
}
