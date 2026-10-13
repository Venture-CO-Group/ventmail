<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services\Pdf;

use RuntimeException;

/**
 * A renderelés hibája, gépi kóddal (`analytics_reports.failure_code`): `render_failed`,
 * `render_timeout`, `too_large`, `renderer_unreachable`, `renderer_misconfigured`.
 */
class PdfRenderFailed extends RuntimeException
{
    public function __construct(public readonly string $failureCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'A PDF renderelése sikertelen: '.$failureCode);
    }
}
