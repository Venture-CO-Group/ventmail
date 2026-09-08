<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Services;

use App\Modules\Deliverability\Contracts\SeedListProvider;
use App\Modules\Deliverability\Models\SeedTest;

/**
 * A seed-mérés kiértékelése (45. kritérium).
 *
 * A jelentés SZOLGÁLTATÓNKÉNT bontva készül, és ez nem részletkérdés: az „összesített 82%
 * beérkező" elrejti, hogy a Gmailen 40% — pedig a legtöbb listán a címek fele Gmail. A
 * kézbesíthetőség szolgáltatónként romlik el, tehát szolgáltatónként kell látni.
 */
final class SeedTestRunner
{
    public function __construct(private readonly SeedListProvider $provider) {}

    public function evaluate(SeedTest $test): SeedTest
    {
        $counts = ['inbox' => 0, 'spam' => 0, 'promotions' => 0, 'missing' => 0];
        $byProvider = [];

        $placements = $this->provider->placements((string) $test->uuid);
        $seen = [];

        foreach ($placements as $placement) {
            $counts[$placement->folder] = ($counts[$placement->folder] ?? 0) + 1;

            $byProvider[$placement->provider] ??= ['inbox' => 0, 'spam' => 0, 'promotions' => 0, 'missing' => 0];
            $byProvider[$placement->provider][$placement->folder]++;

            $seen[] = $placement->address;
        }

        // AMIRŐL NINCS JELENTÉS, az HIÁNYZÓ — nem „még nem tudjuk".
        //
        // Ez a legrosszabb eset, és a leggyakrabban elhallgatott: a levél sehova nem jutott
        // el. Ha kihagynánk a statisztikából, a beérkező arány pont attól lenne szép, amitől
        // rossznak kellene lennie.
        foreach ($this->provider->addresses() as $address) {
            if (! in_array($address, $seen, true)) {
                $counts['missing']++;

                $provider = $this->providerOf($address);
                $byProvider[$provider] ??= ['inbox' => 0, 'spam' => 0, 'promotions' => 0, 'missing' => 0];
                $byProvider[$provider]['missing']++;
            }
        }

        $test->forceFill([
            'inbox' => $counts['inbox'],
            'spam' => $counts['spam'],
            'promotions' => $counts['promotions'],
            'missing' => $counts['missing'],
            'providers' => $byProvider,
            'status' => 'completed',
            'finished_at' => now(),
        ])->save();

        return $test;
    }

    private function providerOf(string $address): string
    {
        $domain = strtolower((string) substr((string) strrchr($address, '@'), 1));

        return $domain === '' ? 'ismeretlen' : $domain;
    }
}
