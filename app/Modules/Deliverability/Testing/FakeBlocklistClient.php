<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Testing;

use App\Modules\Deliverability\Contracts\BlocklistClient;
use App\Modules\Deliverability\Data\BlocklistVerdict;

/** Determinisztikus blocklist a tesztekhez. */
final class FakeBlocklistClient implements BlocklistClient
{
    /** @var array<string, BlocklistVerdict> */
    private array $answers = [];

    /** @var list<string> */
    public array $checked = [];

    public function listOn(string $target, string $zone, string $detail = '127.0.0.2'): self
    {
        $this->answers[$target.'@'.$zone] = BlocklistVerdict::listed($detail);

        return $this;
    }

    public function inconclusive(string $target, string $zone): self
    {
        $this->answers[$target.'@'.$zone] = BlocklistVerdict::inconclusive('lekérdezés-keret');

        return $this;
    }

    public function check(string $target, string $zone): BlocklistVerdict
    {
        $this->checked[] = $target.'@'.$zone;

        return $this->answers[$target.'@'.$zone] ?? BlocklistVerdict::clean();
    }
}
