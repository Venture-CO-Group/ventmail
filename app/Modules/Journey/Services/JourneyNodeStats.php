<?php

declare(strict_types=1);

namespace App\Modules\Journey\Services;

use App\Modules\Journey\Graph\JourneyGraph;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Models\JourneyStep;
use App\Support\QueryBudget;
use App\Support\QueryTooExpensive;

/**
 * CSOMÓPONTONKÉNTI FUTÁSI STATISZTIKA (v1.1 · 6. modul): hányan léptek be, várnak, léptek
 * tovább.
 *
 * ## Korlátos a kérés útján
 *
 * - „Továbblépett": az ELŐRE ÖSSZESÍTETT `journey_steps.executed_count` (a motor növeli a
 *   lépés végrehajtásakor) — a `journey_run_steps` (a legnagyobb tábla) nem olvasódik.
 * - „Vár": a FUTÓ futások csoportosítása a részleges indexen
 *   (`journey_runs_open_step_idx`: csak `active` / `waiting`) — a lezárt futások nincsenek
 *   benne. A lekérdezés `QueryBudget` alatt fut: ha túl drága, a vászon számok NÉLKÜL
 *   rajzolódik ki (`available = false`), nem akad el.
 * - „Belépett" = továbblépett + vár (a triggernél a journey belépés-számlálója).
 *
 * A számok CSOMÓPONT-kulcs szerint összegződnek a verziókon át: a szerkesztőben a csomópont
 * azonosítója verzióról verzióra megmarad, tehát a „Megnyitotta?" csomópont számai a régi
 * verzión haladó kontaktokat is tartalmazzák.
 */
final class JourneyNodeStats
{
    private const BUDGET_MS = 2000;

    public function __construct(private readonly QueryBudget $budget) {}

    /**
     * @return array{available: bool, nodes: array<string, array{entered: int, waiting: int, movedOn: int}>}
     */
    public function forJourney(Journey $journey, JourneyGraph $graph): array
    {
        $steps = JourneyStep::query()
            ->where('journey_id', $journey->getKey())
            ->whereNotNull('node_key')
            ->get(['id', 'node_key', 'executed_count']);

        $nodes = [];
        $keyOf = [];

        foreach ($steps as $step) {
            $key = (string) $step->node_key;
            $keyOf[(int) $step->getKey()] = $key;
            $nodes[$key] ??= ['entered' => 0, 'waiting' => 0, 'movedOn' => 0];
            $nodes[$key]['movedOn'] += (int) $step->executed_count;
        }

        $available = true;

        if ($keyOf !== []) {
            try {
                $waiting = $this->budget->run(fn () => JourneyRun::query()
                    ->where('journey_id', $journey->getKey())
                    ->whereIn('status', ['active', 'waiting'])
                    ->whereNotNull('current_step_id')
                    ->groupBy('current_step_id')
                    ->selectRaw('current_step_id, count(*) as total')
                    ->pluck('total', 'current_step_id')
                    ->all(), self::BUDGET_MS);

                foreach ($waiting as $stepId => $total) {
                    if (isset($keyOf[(int) $stepId])) {
                        $nodes[$keyOf[(int) $stepId]]['waiting'] += (int) $total;
                    }
                }
            } catch (QueryTooExpensive) {
                $available = false;
            }
        }

        foreach ($nodes as $key => $row) {
            $nodes[$key]['entered'] = $row['movedOn'] + $row['waiting'];
        }

        foreach ($graph->triggers() as $trigger) {
            $entered = (int) $journey->entered_count;
            $nodes[$trigger['id']] = ['entered' => $entered, 'waiting' => 0, 'movedOn' => $entered];
        }

        return ['available' => $available, 'nodes' => $nodes];
    }
}
