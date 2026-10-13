<?php

declare(strict_types=1);

namespace App\Modules\Template\Services\Assets;

use App\Modules\Template\Contracts\AssetStore;
use App\Modules\Template\Data\StoredAsset;
use RuntimeException;

/**
 * A KIKAPCSOLT feltöltés: minden `put()` hangos hiba. Ott használjuk, ahol nincs nyilvános
 * tár (pl. egy környezet, ahol a `public` diszk nincs kiszolgálva) — a szerkesztő ilyenkor
 * csak URL-lel ad hozzá képet, és a hiba nem egy halott képlink a levélben.
 */
final class NullAssetStore implements AssetStore
{
    public function name(): string
    {
        return 'null';
    }

    public function put(string $workspaceUuid, string $contents, string $extension): StoredAsset
    {
        throw new RuntimeException('A képfeltöltés ebben a környezetben ki van kapcsolva (template.assets.driver = null).');
    }

    public function delete(string $path): void {}
}
