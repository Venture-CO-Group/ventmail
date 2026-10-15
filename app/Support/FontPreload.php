<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Vite;

/**
 * A két fő betűvágás (törzs: Inter, címsor: Space Grotesk — latin) preload-URL-jei a Vite-manifesztből.
 *
 * A manifeszt-kulcs a @venture/design `file:` függőség feloldott útja (`../../venture-design/dist/fonts/…`),
 * ami környezetenként más lehet — ezért a kulcs UTOLSÓ szakasza alapján keresünk, nem kézzel írt útra.
 * Manifeszt nélkül (teszt, nem buildelt dev) nincs preload: a font ettől még betöltődik, csak később.
 */
final class FontPreload
{
    /** @var list<string> */
    private const CUTS = ['inter-latin.woff2', 'space-grotesk-latin.woff2'];

    /** @return list<string> */
    public static function urls(): array
    {
        $path = (string) config('vite.manifest_path', public_path('build/manifest.json'));
        if (! is_file($path)) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        if (! is_array($manifest)) {
            return [];
        }

        $urls = [];
        foreach (self::CUTS as $cut) {
            foreach ($manifest as $key => $entry) {
                if (is_string($key) && str_ends_with($key, '/fonts/'.$cut) && is_array($entry) && is_string($entry['file'] ?? null)) {
                    $urls[] = asset('build/'.$entry['file']);
                    break;
                }
            }
        }

        return $urls;
    }
}
