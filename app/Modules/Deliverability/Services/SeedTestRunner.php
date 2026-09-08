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

        // A CÍMKE a listától jön, mindkét irányban ugyanaz: a megérkezett és a hiányzó levél
        // is ugyanabba a vödörbe kerül. Enélkül a Gmail egyszer `Gmail`, egyszer `gmail.com`
        // néven szerepelne — és a szolgáltatónkénti romlás pont ott nem látszana.
        $labels = [];

        foreach ($this->provider->addresses() as $seed) {
            $labels[$seed->address] = $seed->provider;
        }

        $placements = $this->provider->placements((string) $test->uuid);
        $seen = [];

        foreach ($placements as $placement) {
            $provider = $labels[$placement->address] ?? $placement->provider;

            $counts[$placement->folder]++;

            $byProvider[$provider] ??= ['inbox' => 0, 'spam' => 0, 'promotions' => 0, 'missing' => 0];
            $byProvider[$provider][$placement->folder]++;

            $seen[] = $placement->address;
        }

        // AMIRŐL NINCS JELENTÉS, az HIÁNYZÓ — nem „még nem tudjuk".
        //
        // Ez a legrosszabb eset, és a leggyakrabban elhallgatott: a levél sehova nem jutott
        // el. Ha kihagynánk a statisztikából, a beérkező arány pont attól lenne szép, amitől
        // rossznak kellene lennie.
        foreach ($this->provider->addresses() as $seed) {
            if (in_array($seed->address, $seen, true)) {
                continue;
            }

            $counts['missing']++;

            $byProvider[$seed->provider] ??= ['inbox' => 0, 'spam' => 0, 'promotions' => 0, 'missing' => 0];
            $byProvider[$seed->provider]['missing']++;
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
}
