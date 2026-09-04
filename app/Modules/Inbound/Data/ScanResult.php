<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Data;

/** Egy szkennelés eredménye. */
final readonly class ScanResult
{
    private function __construct(
        public string $status,
        public ?string $detail = null,
    ) {}

    public static function clean(): self
    {
        return new self('clean');
    }

    public static function infected(string $signature): self
    {
        return new self('infected', $signature);
    }

    /** A szkenner nem elérhető — a csatolmány NEM tölthető le, amíg nem tudjuk. */
    public static function unavailable(string $reason): self
    {
        return new self('unavailable', $reason);
    }
}
