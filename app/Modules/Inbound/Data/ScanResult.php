<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Data;

/** Egy szkennelés eredménye. */
final readonly class ScanResult
{
    private function __construct(
        public string $status,
        public ?string $detail = null,
        /** A szkenner ítélet-azonosítója (ventSec `scan_id`); a ClamAV-nál nincs. */
        public ?string $scanId = null,
    ) {}

    public static function clean(?string $scanId = null): self
    {
        return new self('clean', null, $scanId);
    }

    public static function infected(string $signature, ?string $scanId = null): self
    {
        return new self('infected', $signature, $scanId);
    }

    /** A szkenner nem elérhető — a csatolmány NEM tölthető le, amíg nem tudjuk. */
    public static function unavailable(string $reason, ?string $scanId = null): self
    {
        return new self('unavailable', $reason, $scanId);
    }
}
