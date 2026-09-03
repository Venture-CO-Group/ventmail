<?php

declare(strict_types=1);

namespace App\Modules\Events\Data;

/** Egy nyomkövetési token TARTALMA — a linkből visszafejtve. */
final readonly class TrackingClaim
{
    public function __construct(
        public int $workspaceId,
        public string $messageUuid,
        public string $recipient,
        public ?string $destination = null,
    ) {}
}
