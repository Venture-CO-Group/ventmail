<?php

declare(strict_types=1);

namespace App\Modules\Consent\Data;

/**
 * Egy főkönyvi bizonyíték: a ZÁRT mezőkészletű törzs (nyers cím, IP, UA nélkül) és az
 * Idempotency-Key (ugyanaz a kulcs + ugyanaz a törzs → az eredeti nyugta).
 */
final readonly class ConsentProof
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public array $body,
        public string $idempotencyKey,
    ) {}
}
