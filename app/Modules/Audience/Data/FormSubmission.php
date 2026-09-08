<?php

declare(strict_types=1);

namespace App\Modules\Audience\Data;

/**
 * Egy űrlap-beküldés adatai.
 *
 * A `honeypot` és az `elapsedSeconds` NEM üzleti adat, hanem a bot-szűrés bemenete — és
 * szándékosan itt van, a payload részeként: így a szűrés a beküldés feldolgozásának
 * ELVÁLASZTHATATLAN része, nem egy külön middleware, amit el lehet felejteni bekötni.
 */
final readonly class FormSubmission
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $email,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public array $attributes = [],
        public ?string $honeypot = null,
        public ?int $elapsedSeconds = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}
}
