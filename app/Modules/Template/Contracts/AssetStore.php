<?php

declare(strict_types=1);

namespace App\Modules\Template\Contracts;

use App\Modules\Template\Data\StoredAsset;

/**
 * A SABLON-KÉPEK tárolója (D10).
 *
 * A gazda HOSSZÚ TÁVON a ventVault (a család fájltára — ventMail saját fájltárat nem épít).
 * Amíg a ventVault public-asset API-ja nem él, a `LocalPublicAssetStore` a `public` diszkre
 * ír. A cserét csak a konfiguráció dönti el (`template.assets.driver`), a hívók nem.
 *
 * // TODO ventVault: `VentVaultAssetStore` (HUMAN-QUEUE).
 *
 * A kontraktus: a tár a TENANT-hez kötött útvonalra ír (a hívó adja a workspace UUID-ját),
 * és NYILVÁNOS, `https://` URL-t ad — a levélkliens a képet hitelesítés nélkül tölti le.
 */
interface AssetStore
{
    /** A driver neve (a `template_assets.driver` oszlopba kerül). */
    public function name(): string;

    /**
     * @param  string  $workspaceUuid  a tenant — a tárolási útvonal része
     * @param  string  $contents  a már ELLENŐRZÖTT bájtok (MIME, méret, képméret)
     * @param  string  $extension  `jpg` | `png` | `gif` | `webp`
     */
    public function put(string $workspaceUuid, string $contents, string $extension): StoredAsset;

    public function delete(string $path): void;
}
