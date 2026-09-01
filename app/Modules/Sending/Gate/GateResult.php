<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate;

/** Egyetlen kapu eredménye. A `detail` az üzenet-trace-ben jelenik meg (A3). */
final readonly class GateResult
{
    private function __construct(
        public bool $passed,
        public ?string $reason = null,
        public string $detail = '',
    ) {}

    public static function pass(string $detail = ''): self
    {
        return new self(true, null, $detail);
    }

    public static function reject(GateReason $reason, string $detail = ''): self
    {
        return new self(false, $reason->value, $detail);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['passed' => $this->passed, 'reason' => $this->reason, 'detail' => $this->detail];
    }
}
