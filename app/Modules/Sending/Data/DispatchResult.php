<?php

declare(strict_types=1);

namespace App\Modules\Sending\Data;

/** Egy driver-hívás eredménye. */
final readonly class DispatchResult
{
    public function __construct(
        public bool $accepted,
        public string $providerMessageId,
        public ?string $error = null,
    ) {}

    public static function accepted(string $providerMessageId): self
    {
        return new self(true, $providerMessageId);
    }

    public static function failed(string $error): self
    {
        return new self(false, '', $error);
    }
}
