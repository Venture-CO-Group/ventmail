<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Journey\Graph\JourneyGraph;
use App\Modules\Journey\Graph\LegacyChainGraph;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Services\JourneyNodeStats;
use InvalidArgumentException;

/**
 * JOURNEY: CSOMÓPONTONKÉNTI KONVERZIÓ (v1.1 · 8. modul) — a Journey modul PUBLIKUS
 * szolgáltatásain át (`JourneyNodeStats`, `JourneyGraph`), saját lekérdezés nélkül.
 *
 * A sorok a triggertől induló SZÉLESSÉGI bejárás sorrendjében jönnek; ha egy csomópontnak több
 * kimenete van (feltétel, elágazás, A/B), a gyerek-sor megkapja az ág címkéjét (`branch` = a
 * kimenet kulcsa), így az ágak külön sorban, címkézve látszanak.
 *
 * - `conversion` = továbbléptek / beléptek (%);
 * - a CÉL-konverzió = `goal_reached` kilépés / belépett a journey-be (%).
 *
 * Ha a futó-számlálás túllépi a keretet (`available = false`), a felület „a számok most nem
 * érhetők el" feliratot mutat — nem nullákat.
 */
final class JourneyConversion
{
    /** Ennyi sornál hosszabb journey csonkolódik a riportban (a vászon a teljes képet adja). */
    public const MAX_ROWS = 60;

    public function __construct(
        private readonly JourneyNodeStats $stats,
        private readonly JourneyAnalytics $analytics,
    ) {}

    /**
     * @return array{available: bool, rows: list<array{id: string, type: string, label: string, depth: int, branch: ?string, entered: int, movedOn: int, conversion: float}>, goal: array{reached: int, entered: int, rate: float}}
     */
    public function forJourney(Journey $journey): array
    {
        try {
            $graph = is_array($journey->graph) ? JourneyGraph::fromArray($journey->graph) : LegacyChainGraph::from($journey);
        } catch (InvalidArgumentException) {
            $graph = JourneyGraph::empty();
        }

        $stats = $this->stats->forJourney($journey, $graph);
        $rows = [];
        $seen = [];
        $queue = array_map(static fn (array $node): array => [$node['id'], 0, null], $graph->triggers());

        while ($queue !== [] && count($rows) < self::MAX_ROWS) {
            [$id, $depth, $branch] = array_shift($queue);

            if (isset($seen[$id]) || ($node = $graph->node($id)) === null) {
                continue;
            }

            $seen[$id] = true;
            $numbers = $stats['nodes'][$id] ?? ['entered' => 0, 'waiting' => 0, 'movedOn' => 0];

            $rows[] = [
                'id' => $id,
                'type' => (string) $node['type'],
                'label' => (string) $node['label'],
                'depth' => $depth,
                'branch' => $branch,
                'entered' => (int) $numbers['entered'],
                'movedOn' => (int) $numbers['movedOn'],
                'conversion' => Rates::percent((int) $numbers['movedOn'], (int) $numbers['entered']),
            ];

            $outgoing = $graph->outgoing($id);
            $branching = count(array_unique(array_map(static fn (array $edge): string => $edge['sourceHandle'], $outgoing))) > 1;

            foreach ($outgoing as $edge) {
                $queue[] = [$edge['target'], $depth + 1, $branching ? $edge['sourceHandle'] : null];
            }
        }

        $entered = (int) $journey->entered_count;
        $reached = (int) ($this->analytics->exitReasons($journey)['goal_reached'] ?? 0);

        return [
            'available' => (bool) $stats['available'],
            'rows' => $rows,
            'goal' => ['reached' => $reached, 'entered' => $entered, 'rate' => Rates::percent($reached, $entered)],
        ];
    }
}
