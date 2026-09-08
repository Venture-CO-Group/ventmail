<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Data;

/** Egy seed-postafiók eredménye: hova érkezett a levél. */
final readonly class SeedPlacement
{
    public const INBOX = 'inbox';

    public const SPAM = 'spam';

    public const PROMOTIONS = 'promotions';

    /** Sehova nem érkezett meg — ez a legrosszabb eset, és a leggyakrabban elhallgatott. */
    public const MISSING = 'missing';

    public function __construct(
        public string $address,
        public string $provider,
        public string $folder,
    ) {}
}
