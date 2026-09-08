<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Data;

/** Egy forrás-IP egy DMARC-jelentésben. */
final readonly class DmarcRow
{
    public function __construct(
        public string $sourceIp,
        public int $count,
        public string $disposition,
        public string $spf,
        public string $dkim,
        public string $headerFrom,
    ) {}

    /**
     * Átment-e a DMARC-on?
     *
     * SPF **VAGY** DKIM — a DMARC igazodási szabálya szerint az egyik elég (RFC 7489 §6.6.2).
     * Aki mindkettőt megköveteli, a saját, teljesen szabályos továbbított leveleit is
     * bukottnak könyveli.
     */
    public function passes(): bool
    {
        return strtolower($this->spf) === 'pass' || strtolower($this->dkim) === 'pass';
    }
}
