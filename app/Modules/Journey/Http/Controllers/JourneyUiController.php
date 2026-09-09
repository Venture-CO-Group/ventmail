<?php

declare(strict_types=1);

namespace App\Modules\Journey\Http\Controllers;

use App\Modules\Journey\Enums\StepType;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Journey\Services\JourneyValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * JOURNEY-K (68–71. kritérium): lista, lépéslánc, aktiválás.
 *
 * ## A LÁNC LISTA, nem vászon — és ez tudatos
 *
 * A `@venture/ui`-ban nincs gráf- vagy vászon-primitív, a golden rule pedig a lokális
 * komponens-gyártást tiltja (jelenteni kell, nem megkerülni). A lépéslánc viszont
 * SORREND: az első lépéstől a következőig, elágazásnál `else`-ággal. Ez listaként
 * pontosan leírható, billentyűzettel is bejárható — a vizuális vászon a `@venture/ui`
 * bővítése után jöhet.
 *
 * ## AZ AKTIVÁLÁS validál
 *
 * A `JourneyValidator` a láncot (nincs kör, van kilépés), a lépéseket és a célt nézi. Ha
 * ez csak futásidőben derülne ki, a journey „aktív" lenne, és a címzettek beragadnának
 * egy hurokba.
 */
final class JourneyUiController
{
    public function index(): Response
    {
        Gate::authorize('campaign.create');

        return Inertia::render('Journey/Index', [
            'journeys' => Journey::query()
                ->withCount('steps')
                ->orderBy('name')
                ->get()
                ->map(fn (Journey $journey) => [
                    'uuid' => (string) $journey->uuid,
                    'name' => (string) $journey->name,
                    'status' => (string) $journey->status,
                    'trigger' => (string) $journey->trigger_type,
                    'steps' => (int) $journey->steps_count,
                    // A FUTÓ példányok száma az, ami megmondja, hogy a journey ÉL-e:
                    // egy aktív journey nulla futással konfigurációs hiba.
                    'activeRuns' => JourneyRun::query()
                        ->where('journey_id', $journey->getKey())
                        ->where('status', 'active')
                        ->count(),
                ])->values()->all(),
            'triggers' => array_map(fn (TriggerType $type) => $type->value, TriggerType::cases()),
        ]);
    }

    public function show(string $uuid): Response
    {
        Gate::authorize('campaign.create');

        $journey = Journey::query()->with('steps')->where('uuid', $uuid)->firstOrFail();

        $steps = $journey->steps->keyBy('id');

        return Inertia::render('Journey/Edit', [
            'journey' => [
                'uuid' => (string) $journey->uuid,
                'name' => (string) $journey->name,
                'description' => $journey->description,
                'status' => (string) $journey->status,
                'trigger' => (string) $journey->trigger_type,
                'allowReentry' => (bool) $journey->allow_reentry,
                'firstStep' => $journey->first_step_id === null
                    ? null
                    : (string) ($steps[$journey->first_step_id]?->uuid ?? ''),
            ],
            // A LÁNC SORRENDBEN: a felület nem gráfot rajzol, hanem az utat mutatja meg.
            'steps' => $journey->steps->sortBy('position')->map(fn (JourneyStep $step) => [
                'uuid' => (string) $step->uuid,
                'type' => (string) $step->type,
                'position' => (int) $step->position,
                'config' => (array) $step->config,
                'next' => $step->next_step_id === null ? null : (string) ($steps[$step->next_step_id]?->uuid ?? ''),
                'else' => $step->else_step_id === null ? null : (string) ($steps[$step->else_step_id]?->uuid ?? ''),
            ])->values()->all(),
            'stepTypes' => array_map(fn (StepType $type) => $type->value, StepType::cases()),
            'runs' => [
                'active' => JourneyRun::query()->where('journey_id', $journey->getKey())->where('status', 'active')->count(),
                'completed' => JourneyRun::query()->where('journey_id', $journey->getKey())->where('status', 'completed')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('campaign.create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', 'string', Rule::in(array_map(fn (TriggerType $type) => $type->value, TriggerType::cases()))],
        ]);

        $journey = Journey::create([
            'name' => $validated['name'],
            'trigger_type' => $validated['trigger'],
        ]);

        return redirect()->route('journeys.show', ['uuid' => $journey->uuid]);
    }

    public function activate(string $uuid, JourneyValidator $validator): RedirectResponse
    {
        // AZ AKTIVÁLÁS küldést indít — ugyanaz a felelősség, mint a kampány kiküldése.
        Gate::authorize('campaign.publish');

        $journey = Journey::query()->with('steps')->where('uuid', $uuid)->firstOrFail();

        try {
            $validator->validateChain($journey);
            $validator->validateGoal($journey);
        } catch (InvalidArgumentException $e) {
            /*
             | A LÁNC HIBÁJA az AKTIVÁLÁSNÁL derül ki, nem a századik címzettnél.
             |
             | Egy körkörös lánc vagy kilépés nélküli journey a futásban ragadna be — és
             | ott már címzettek vannak benne.
             */
            return back()->with('error', $e->getMessage());
        }

        $journey->forceFill(['status' => 'active'])->save();

        return back()->with('success', __('ventmail.journeys.activated'));
    }

    public function pause(string $uuid): RedirectResponse
    {
        Gate::authorize('campaign.publish');

        $journey = Journey::query()->where('uuid', $uuid)->firstOrFail();

        // A SZÜNET nem törli a futásokat: a benne lévő címzettek ott maradnak, ahol
        // vannak, és a folytatáskor onnan mennek tovább.
        $journey->forceFill(['status' => 'paused'])->save();

        return back()->with('success', __('ventmail.journeys.paused'));
    }
}
