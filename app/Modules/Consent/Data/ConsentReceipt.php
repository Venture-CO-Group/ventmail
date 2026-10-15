<?php

declare(strict_types=1);

namespace App\Modules\Consent\Data;

/**
 * A főkönyv nyugtája. A `durable = false` (fake meghajtó) nyugta NEM kerül a consent-rekordra:
 * egy valótlan nyugta-azonosító hamis bizonyítéknak látszana.
 */
final readonly class ConsentReceipt
{
    public function __construct(
        public string $receiptId,
        public bool $durable = true,
    ) {}
}
