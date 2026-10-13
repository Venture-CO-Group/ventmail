<?php

declare(strict_types=1);

use App\Modules\Analytics\Services\JourneyConversion;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Models\JourneyStep;
use App\Support\QueryBudget;
use App\Support\QueryTooExpensive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AnalyticsFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 8. modul — csomópontonkénti konverzió a Journey PUBLIKUS szolgáltatásain át: BFS a
 | triggertől, az ágak külön, címkézett sorban; cél-konverzió a `goal_reached` kilépésből.
 */

function vmJourney(): Journey
{
    $template = AnalyticsFixtures::template();

    $journey = Journey::create([
        'name' => 'Üdvözlő',
        'trigger_type' => 'api_event',
        'graph' => [
            'nodes' => [
                ['id' => 't', 'type' => 'api_event', 'label' => 'Belépés', 'config' => ['event' => 'signup']],
                ['id' => 'mail', 'type' => 'send_email', 'label' => '', 'config' => ['template' => (string) $template->uuid]],
                ['id' => 'cond', 'type' => 'condition', 'label' => 'Megnyitotta?', 'config' => []],
                ['id' => 'yes', 'type' => 'goal', 'label' => 'Cél', 'config' => []],
                ['id' => 'no', 'type' => 'exit', 'label' => 'Kilépés', 'config' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't', 'sourceHandle' => 'out', 'target' => 'mail'],
                ['id' => 'e2', 'source' => 'mail', 'sourceHandle' => 'out', 'target' => 'cond'],
                ['id' => 'e3', 'source' => 'cond', 'sourceHandle' => 'yes', 'target' => 'yes'],
                ['id' => 'e4', 'source' => 'cond', 'sourceHandle' => 'no', 'target' => 'no'],
            ],
        ],
    ]);
    $journey->forceFill(['entered_count' => 100])->saveQuietly();

    $step = JourneyStep::create(['journey_id' => $journey->getKey(), 'type' => 'send_email', 'config' => ['template' => (string) $template->uuid], 'node_key' => 'mail']);
    $step->forceFill(['executed_count' => 80])->saveQuietly();

    foreach (range(1, 20) as $i) {
        JourneyRun::query()->create(['journey_id' => $journey->getKey(), 'email' => "c{$i}@pelda.hu", 'status' => $i <= 5 ? 'completed' : 'active', 'current_step_id' => $i <= 5 ? null : $step->getKey(), 'exit_reason' => $i <= 5 ? 'goal_reached' : null]);
    }

    return $journey;
}

it('BFS-sorrend, ágcímke, konverzió és cél-arány', function () {
    AnalyticsFixtures::tenant();
    $journey = vmJourney();

    $result = app(JourneyConversion::class)->forJourney($journey);

    expect($result['available'])->toBeTrue()
        ->and(array_column($result['rows'], 'id'))->toBe(['t', 'mail', 'cond', 'yes', 'no'])
        ->and($result['rows'][0]['entered'])->toBe(100)
        // mail: továbblépett 80, vár 15 → belépett 95.
        ->and($result['rows'][1]['entered'])->toBe(95)
        ->and($result['rows'][1]['movedOn'])->toBe(80)
        ->and($result['rows'][1]['conversion'])->toBe(84.21)
        ->and($result['rows'][3]['branch'])->toBe('yes')
        ->and($result['rows'][4]['branch'])->toBe('no')
        ->and($result['rows'][1]['branch'])->toBeNull()
        ->and($result['goal'])->toBe(['reached' => 5, 'entered' => 100, 'rate' => 5.0]);
});

it('túl drága futás-számlálás → available:false (nem nullák)', function () {
    AnalyticsFixtures::tenant();
    $journey = vmJourney();

    app()->instance(QueryBudget::class, new class extends QueryBudget
    {
        public function run(callable $work, ?int $milliseconds = null): mixed
        {
            throw new QueryTooExpensive('teszt');
        }
    });

    expect(app(JourneyConversion::class)->forJourney($journey)['available'])->toBeFalse();
});
