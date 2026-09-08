<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Testing;

use App\Modules\Deliverability\Contracts\SeedListProvider;
use App\Modules\Deliverability\Data\SeedAddress;
use App\Modules\Deliverability\Data\SeedPlacement;

/** Determinisztikus seed-lista a tesztekhez. */
final class FakeSeedListProvider implements SeedListProvider
{
    /** @var list<SeedPlacement> */
    private array $placements = [];

    /** @var list<SeedAddress> */
    public array $addresses;

    public function __construct()
    {
        $this->addresses = [
            new SeedAddress('seed1@gmail.example', 'Gmail'),
            new SeedAddress('seed2@outlook.example', 'Outlook'),
            new SeedAddress('seed3@yahoo.example', 'Yahoo'),
        ];
    }

    public function place(string $address, string $provider, string $folder): self
    {
        $this->placements[] = new SeedPlacement($address, $provider, $folder);

        return $this;
    }

    public function addresses(): array
    {
        return $this->addresses;
    }

    public function placements(string $testUuid): array
    {
        return $this->placements;
    }
}
