<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services\Pdf;

/** A bemenő HTML vagy a kimenő PDF túllépte a méretkorlátot. */
final class PdfTooLarge extends PdfRenderFailed
{
    public function __construct(string $message = '')
    {
        parent::__construct('too_large', $message);
    }
}
