<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Data;

/**
 * A csatorna titkosítottsága — a TRANSZPORT által ÁTADOTT tény.
 *
 * Az állapotgép ezt sosem találja ki: nem lát socketet, nem kérdez stream-kontextust. Így a
 * 13. kritérium (plaintext auth tilos) hálózat nélkül is bizonyítható, és a transzport
 * cseréje nem érinti a szabályt.
 */
final readonly class ChannelSecurity
{
    private function __construct(
        public bool $encrypted,
        public ?string $protocol = null,
    ) {}

    public static function plaintext(): self
    {
        return new self(false);
    }

    public static function encrypted(string $protocol): self
    {
        return new self(true, $protocol);
    }
}
