<?php

declare(strict_types=1);

namespace App\Modules\Template\Services\Assets;

/** Az `ImageInspector` által elfogadott kép tartalomból mért adatai. */
final class InspectedImage
{
    public function __construct(
        public readonly string $mime,
        public readonly string $extension,
        public readonly int $width,
        public readonly int $height,
    ) {}
}
