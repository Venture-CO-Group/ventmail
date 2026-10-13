<?php

declare(strict_types=1);

namespace App\Modules\Journey\Http\Controllers;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Graph\NodeCatalog;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Services\JourneyGraphInvalid;
use App\Modules\Journey\Services\JourneyPublisher;
use App\Modules\Journey\Services\JourneyValidator;
use App\Modules\Template\Services\TemplateUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * JOURNEY-K (68–71. kritérium): lista, létrehozás, a régi lánc aktiválása.
 *
 * A v1.1 óta a journey a VÁSZONON szerkesztődik (`JourneyCanvasController`, D4/D5); az új
 * journey a választott trigger-csomóponttal indul. A v1.1 előtti, láncolt journey a vásznon
 * is megnyílik, és amíg nem publikálják újra, a régi lánca fut.
 *
 * ## AZ AKTIVÁLÁS validál
 *
 * A vászon journey-jét a `JourneyGraphValidator` (árva csomópont, hiányzó trigger, várakozás
 * nélküli kör, kötelező konfiguráció), a régi láncot a `JourneyValidator` nézi. Ha ez csak
 * futásidőben derülne ki, a journey „aktív" lenne, és a címzettek beragadnának egy hurokba.
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
                    // A VÁSZON journey-jénél a gráf akció-csomópontjai számítanak (a verziók
                    // lépései összeadva megtévesztők lennének); a régi láncnál a lépések.
                    'steps' => is_array($journey->graph)
                        ? count(array_filter((array) ($journey->graph['nodes'] ?? []), static fn ($node): bool => is_array($node) && NodeCatalog::isAction((string) ($node['type'] ?? ''))))
                        : (int) $journey->steps_count,
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
            // v1.1 · a VÁSZON a választott trigger-csomóponttal indul.
            'graph' => [
                'nodes' => [[
                    'id' => 'trigger',
                    'type' => $validated['trigger'],
                    'position' => ['x' => 0, 'y' => 0],
                    'label' => '',
                    'config' => [],
                ]],
                'edges' => [],
            ],
        ]);

        return redirect()->route('journeys.show', ['uuid' => $journey->uuid]);
    }

    public function activate(Request $request, string $uuid, JourneyValidator $validator, JourneyPublisher $publisher, AuditLogger $audit): RedirectResponse
    {
        // AZ AKTIVÁLÁS küldést indít — ugyanaz a felelősség, mint a kampány kiküldése. A v1.1
        // óta saját képesség (`journey.publish`), ugyanazokkal a szerepekkel.
        Gate::authorize('journey.publish');

        $journey = Journey::query()->with('steps')->where('uuid', $uuid)->firstOrFail();

        // A VÁSZON journey-je a publikálón megy (verzió + lépések), a régi lánc a régi úton.
        if (is_array($journey->graph)) {
            try {
                /** @var User|null $actor */
                $actor = $request->user();
                $publisher->publish($journey, $actor);
            } catch (JourneyGraphInvalid) {
                return back()->with('error', __('ventmail.journeys.publishInvalid'));
            }

            return back()->with('success', __('ventmail.journeys.activated'));
        }

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

        DB::transaction(function () use ($journey, $audit): void {
            // A `send_email` lépések sablonjainak megosztott zárja (review SEC4-S1) — mint a vászon
            // publikálásánál: az aktiválás után a szerző már nem írhatja át a kiküldendő tartalmat.
            app(TemplateUsage::class)->lockForActivationByUuid($journey->steps
                ->where('type', 'send_email')
                ->map(static fn ($step): string => (string) (((array) $step->config)['template'] ?? ''))
                ->values()->all());

            $journey->forceFill(['status' => 'active'])->save();

            $audit->log('journey.activated', $journey, context: ['legacy_chain' => true]);
        });

        return back()->with('success', __('ventmail.journeys.activated'));
    }
}
