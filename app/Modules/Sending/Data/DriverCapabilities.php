<?php

declare(strict_types=1);

namespace App\Modules\Sending\Data;

/** Mit tud az adott driver — a magasabb rétegek ebből döntenek, nem típus-ellenőrzésből. */
final readonly class DriverCapabilities
{
    public function __construct(
        public string $name,
        public bool $supportsRawMime = false,
        public bool $supportsIpPools = false,
        public bool $supportsScheduling = false,
        public bool $supportsTracking = false,
    ) {}
}
