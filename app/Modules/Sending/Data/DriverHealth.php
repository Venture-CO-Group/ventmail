<?php

declare(strict_types=1);

namespace App\Modules\Sending\Data;

/** A driver pillanatnyi állapota (a super-admin cluster-health nézet alapja, C2). */
final readonly class DriverHealth
{
    public function __construct(
        public bool $healthy,
        public string $detail = '',
    ) {}
}
