<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Data;

/** Egy DMARC RUA jelentés. */
final readonly class DmarcReport
{
    /** @param list<DmarcRow> $rows */
    public function __construct(
        public string $orgName,
        public string $reportId,
        public string $domain,
        public string $policy,
        public int $rangeStart,
        public int $rangeEnd,
        public array $rows = [],
    ) {}

    public function total(): int
    {
        return array_sum(array_map(fn (DmarcRow $row) => $row->count, $this->rows));
    }

    /** Hány üzenet ment át a DMARC-on (SPF VAGY DKIM igazodással). */
    public function passed(): int
    {
        return array_sum(array_map(
            fn (DmarcRow $row) => $row->passes() ? $row->count : 0,
            $this->rows,
        ));
    }

    public function failed(): int
    {
        return $this->total() - $this->passed();
    }
}
