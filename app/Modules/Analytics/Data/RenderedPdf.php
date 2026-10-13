<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

final class RenderedPdf
{
    public readonly string $sha256;

    public function __construct(public readonly string $bytes, public readonly ?int $pageCount = null)
    {
        $this->sha256 = hash('sha256', $bytes);
    }

    /** Oldalszám a PDF-ből (a `/Type /Page` objektumok), ha a renderer nem adta meg. */
    public static function countPages(string $bytes): ?int
    {
        $count = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $bytes);

        return $count > 0 ? $count : null;
    }
}
