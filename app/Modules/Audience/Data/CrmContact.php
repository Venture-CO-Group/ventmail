<?php

declare(strict_types=1);

namespace App\Modules\Audience\Data;

use Illuminate\Support\Carbon;

/** Egy kontakt a CRM (ventOS) oldalán — a szinkron kontraktusának adatalakja. */
final readonly class CrmContact
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $externalId,
        public string $email,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public array $attributes = [],
        public ?Carbon $updatedAt = null,
    ) {}
}
