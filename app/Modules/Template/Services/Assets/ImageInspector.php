<?php

declare(strict_types=1);

namespace App\Modules\Template\Services\Assets;

/**
 * KÉP-ELLENŐRZŐ LÁNC — a sablon-képfeltöltés (`AssetUploader`) és a workspace-logó
 * (`BrandingController`, v1.1 · 8. modul) KÖZÖS, publikus szolgáltatása. Egy lánc, nem másolat:
 * egy új bomba-minta ellen egy helyen kell védekezni.
 *
 * - a MIME a TARTALOMBÓL jön (`finfo` + `getimagesizefromstring`), és a kettőnek egyeznie kell;
 *   az SVG soha nem engedett (szkriptet hordozhat);
 * - képméret-korlát (dekompressziós bomba: egy 50 KB-os PNG is lehet 30 000 × 30 000 pixel);
 * - progresszív JPEG scan-korlát (CPU-bomba);
 * - ÚJRAKÓDOLÁS GD-vel metaadat (EXIF/XMP) nélkül; GD nélkül fail-closed.
 *
 * Hibánál `ImageRejected` a hívó által fordítandó okkal (`type`, `dimensions`, `processing`) —
 * a felhasználói üzenet a hívó modul nyelvi kulcsa.
 */
final class ImageInspector
{
    /** A progresszív JPEG scan-jeinek felső száma (S2) — egy valódi kép 10 körül van. */
    public const MAX_JPEG_SCANS = 100;

    /**
     * @param  array<string, string>  $allowed  MIME → kiterjesztés
     *
     * @throws ImageRejected
     */
    public function inspect(string $contents, array $allowed, int $maxDimension, int $minDimension = 1): InspectedImage
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $info = @getimagesizefromstring($contents);

        if (! isset($allowed[$mime]) || $info === false || ($info['mime'] ?? null) !== $mime) {
            throw new ImageRejected('type');
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];

        if ($width < max(1, $minDimension) || $height < max(1, $minDimension) || $width > $maxDimension || $height > $maxDimension) {
            throw new ImageRejected('dimensions');
        }

        // PROGRESSZÍV JPEG CPU-bomba (S2): a dekódolás ideje a scan-ek számával nő. A
        // scan-fejléc (SOS, `FF DA`) az entrópia-kódolt adatban nem fordulhat elő (ott a
        // `FF` bájt `FF 00`-ként töltődik), tehát a puszta számlálás pontos.
        if ($mime === 'image/jpeg' && substr_count($contents, "\xFF\xDA") > self::MAX_JPEG_SCANS) {
            throw new ImageRejected('type');
        }

        return new InspectedImage($mime, $allowed[$mime], $width, $height);
    }

    /**
     * ÚJRAKÓDOLÁS metaadat nélkül (SS5): az EXIF (GPS, készülék, név) különben a kiszolgált
     * fájlban bárkinek olvasható lenne. A GIF-et nem kódoljuk újra (az animáció a GD-n
     * elveszne), hanem a blokkjait szűrjük (`GifMetadata`).
     *
     * @throws ImageRejected
     */
    public function reencode(string $contents, string $mime): string
    {
        if ($mime === 'image/gif') {
            try {
                return GifMetadata::strip($contents);
            } catch (\InvalidArgumentException) {
                throw new ImageRejected('type');
            }
        }

        if (! function_exists('imagecreatefromstring')) {
            throw new ImageRejected('processing');
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            throw new ImageRejected('type');
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
            throw new ImageRejected('processing');
        }

        return $out;
    }
}
