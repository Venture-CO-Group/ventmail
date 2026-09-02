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
        /**
         * A driver a KÉZBESÍTÉST is visszaigazolta.
         *
         * Egy valódi MTA 2xx-e csak annyit jelent, hogy az üzenet sorba állt — a
         * `delivered` később, a webhookból jön (A3). Ha ezt itt azonnal `delivered`-nek
         * vennénk, a kézbesíthetőségi statisztika az első naptól hamis lenne.
         */
        public bool $delivered = false,
    ) {}

    /** Az MTA átvette (de még nem kézbesítette). */
    public static function accepted(string $providerMessageId): self
    {
        return new self(true, $providerMessageId);
    }

    /** A driver a kézbesítést is visszaigazolta (fake/log driver, vagy szinkron MTA). */
    public static function delivered(string $providerMessageId): self
    {
        return new self(true, $providerMessageId, delivered: true);
    }

    public static function failed(string $error): self
    {
        return new self(false, '', $error);
    }
}
