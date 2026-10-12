<?php

declare(strict_types=1);

namespace App\Modules\Template\Data;

/** Egy tárolt kép helye: a tárbeli útvonal és a nyilvános URL. */
final readonly class StoredAsset
{
    public function __construct(
        public string $path,
        public string $url,
    ) {}
}
