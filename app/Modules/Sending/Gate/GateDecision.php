<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate;

/**
 * A hat kapu együttes döntése.
 *
 * A `results` akkor is megőrzi a lefutott kapuk eredményét, ha a lánc félúton megállt —
 * ez az üzenet-trace („miért nem érkezett meg?", A3) alapja.
 */
final readonly class GateDecision
{
    /** @param array<string, GateResult> $results */
    private function __construct(
        public bool $allowed,
        public ?string $reason,
        public array $results,
    ) {}

    /** @param array<string, GateResult> $results */
    public static function allowed(array $results): self
    {
        return new self(true, null, $results);
    }

    /** @param array<string, GateResult> $results */
    public static function rejected(?string $reason, array $results): self
    {
        return new self(false, $reason, $results);
    }

    /** @return array<string, array<string, mixed>> */
    public function trace(): array
    {
        return array_map(static fn (GateResult $result) => $result->toArray(), $this->results);
    }
}
