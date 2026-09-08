<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Data;

/** Egy blocklist-lekérdezés eredménye. */
final readonly class BlocklistVerdict
{
    private function __construct(
        public bool $listed,
        public ?string $detail = null,
        public bool $conclusive = true,
    ) {}

    public static function clean(): self
    {
        return new self(false);
    }

    public static function listed(string $detail): self
    {
        return new self(true, $detail);
    }

    /**
     * Nem tudtuk megkérdezni.
     *
     * Ez NEM „tiszta". A DNSBL-szolgáltatók a lekérdezés-keret túllépését is válasszal
     * jelzik, és aki ezt „listán van"-nak veszi, hamis riasztást ad; aki „tisztának", az
     * elhallgat egy valódi problémát. A harmadik állapot mindkettőnél jobb.
     */
    public static function inconclusive(string $detail): self
    {
        return new self(false, $detail, false);
    }
}
