<?php

declare(strict_types=1);

namespace App\Modules\Journey\Graph;

use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyStep;

/**
 * A v1.1 ELŐTTI, láncolt listás journey gráffá alakítása — CSAK megjelenítéshez.
 *
 * A vászon így a régi journey-t is meg tudja nyitni. Amíg a gráfot nem publikálják, a régi
 * lépések futnak tovább; a publikálás az első verziót hozza létre, és a régi láncon álló
 * futások a saját lépéseiken fejeződnek be (a verzió-őr a `NULL` verziót a `NULL` lépéshez
 * engedi).
 */
final class LegacyChainGraph
{
    private const SPACING_X = 280;

    private const SPACING_Y = 140;

    public static function from(Journey $journey): JourneyGraph
    {
        $steps = JourneyStep::query()
            ->where('journey_id', $journey->getKey())
            ->whereNull('journey_version_id')
            ->orderBy('position')
            ->limit(JourneyGraph::MAX_NODES - 1)
            ->get()
            ->keyBy('id');

        $nodes = [[
            'id' => 'trigger',
            'type' => (string) $journey->trigger_type,
            'position' => ['x' => 0, 'y' => 0],
            'label' => '',
            'config' => (array) $journey->trigger_config,
        ]];
        $edges = [];
        $key = static fn (int $id): string => 'step-'.$id;

        // A LÁNC sorrendjében rakjuk ki (szélességi bejárás a belépési lépéstől).
        $depth = [];
        $queue = [];

        if ($journey->first_step_id !== null && isset($steps[$journey->first_step_id])) {
            $depth[(int) $journey->first_step_id] = 1;
            $queue[] = (int) $journey->first_step_id;
            $edges[] = ['id' => 'e-trigger', 'source' => 'trigger', 'sourceHandle' => 'out', 'target' => $key((int) $journey->first_step_id)];
        }

        while ($queue !== []) {
            $id = array_shift($queue);

            foreach ([$steps[$id]->next_step_id, $steps[$id]->else_step_id] as $next) {
                if ($next !== null && isset($steps[$next]) && ! isset($depth[(int) $next])) {
                    $depth[(int) $next] = $depth[$id] + 1;
                    $queue[] = (int) $next;
                }
            }
        }

        $rows = [];

        foreach ($steps as $id => $step) {
            $column = $depth[(int) $id] ?? 0;
            $row = $rows[$column] = ($rows[$column] ?? -1) + 1;
            $type = (string) $step->type;
            $config = (array) $step->config;

            if (in_array($type, ['add_tag', 'remove_tag'], true)) {
                $config = ['operation' => $type, 'tag' => $config['tag'] ?? null];
                $type = 'update_contact';
            }

            $nodes[] = [
                'id' => $key((int) $id),
                'type' => $type,
                'position' => ['x' => $column * self::SPACING_X, 'y' => ($column === 0 ? $row + 1 : $row) * self::SPACING_Y],
                'label' => '',
                'config' => $config,
            ];

            $handles = $type === 'condition' ? ['yes', 'no'] : ['out', null];

            foreach ([$step->next_step_id, $step->else_step_id] as $index => $next) {
                if ($next !== null && isset($steps[$next]) && $handles[$index] !== null) {
                    $edges[] = ['id' => 'e-'.$id.'-'.$index, 'source' => $key((int) $id), 'sourceHandle' => $handles[$index], 'target' => $key((int) $next)];
                }
            }
        }

        return JourneyGraph::fromArray(['nodes' => $nodes, 'edges' => $edges]);
    }
}
