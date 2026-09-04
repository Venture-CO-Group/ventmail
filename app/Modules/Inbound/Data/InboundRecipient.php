<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Data;

/** Egy FELOLDOTT bejövő címzett: a mi domainünk, és tudjuk, melyik tenanté. */
final readonly class InboundRecipient
{
    public function __construct(
        public int $workspaceId,
        public int $domainId,
        public string $address,
    ) {}
}
