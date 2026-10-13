<?php

declare(strict_types=1);

namespace App\Modules\Journey\Http\Controllers;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Journey\Graph\JourneyGraph;
use App\Modules\Journey\Graph\JourneyGraphValidator;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Services\JourneyGraphInvalid;
use App\Modules\Journey\Services\JourneyPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * A JOURNEY VÁSZON (v1.1 · 6. modul).
 *
 * Képességek (a kampány mintája — az `author` szerkeszt, nem indít):
 * - szerkesztés, validálás: `campaign.create` (a meglévő journey-szerkesztés
 *   képessége);
 * - PUBLIKÁLÁS (aktiválás / új verzió) és SZÜNETELTETÉS: `journey.publish` — ez küldést indít
 *   vagy állít le, ugyanaz a felelősség, mint a kampány kiküldése. Mindkettő auditált.
 */
final class JourneyCanvasController
{
    /** VÁZLAT mentése — mindig menthető, a hibák figyelmeztetésként jönnek vissza. */
    public function save(Request $request, string $uuid, JourneyGraphValidator $validator): JsonResponse
    {
        Gate::authorize('campaign.create');

        $validated = $request->validate([
            'graph' => ['required', 'array'],
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        $journey = Journey::query()->with('currentVersion')->where('uuid', $uuid)->firstOrFail();

        try {
            $graph = JourneyGraph::fromArray($validated['graph']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $journey->forceFill(array_filter([
            'graph' => $graph->toArray(),
            'name' => isset($validated['name']) ? trim($validated['name']) : null,
        ], static fn ($value): bool => $value !== null && $value !== ''))->save();

        return response()->json([
            'issues' => $validator->validate($graph),
            'dirty' => $journey->currentVersion === null || $journey->currentVersion->graph_hash !== $graph->hash(),
        ]);
    }

    /** VALIDÁLÁS mentés nélkül. */
    public function validateGraph(Request $request, string $uuid, JourneyGraphValidator $validator): JsonResponse
    {
        Gate::authorize('campaign.create');

        Journey::query()->where('uuid', $uuid)->firstOrFail();

        try {
            $graph = JourneyGraph::fromArray((array) $request->input('graph', []));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['issues' => $validator->validate($graph)]);
    }

    /**
     * PUBLIKÁLÁS: aktiválás vagy — aktív journey-nél — ÚJ VERZIÓ. A bent lévő kontaktok a régi
     * verzión haladnak tovább.
     */
    public function publish(Request $request, string $uuid, JourneyPublisher $publisher): JsonResponse
    {
        Gate::authorize('journey.publish');

        $journey = Journey::query()->where('uuid', $uuid)->firstOrFail();

        if ($request->has('graph')) {
            try {
                $journey->forceFill(['graph' => JourneyGraph::fromArray((array) $request->input('graph'))->toArray()])->save();
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        try {
            /** @var User|null $actor */
            $actor = $request->user();
            $version = $publisher->publish($journey, $actor);
        } catch (JourneyGraphInvalid $e) {
            return response()->json(['message' => __('ventmail.journeys.publishInvalid'), 'issues' => $e->issues], 422);
        }

        return response()->json([
            'message' => __('ventmail.journeys.published', ['version' => $version->number]),
            'version' => (int) $version->number,
            'status' => 'active',
        ]);
    }

    /** SZÜNETELTETÉS: a futások ott maradnak, ahol vannak, és a folytatáskor onnan mennek tovább. */
    public function pause(Request $request, string $uuid, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        Gate::authorize('journey.publish');

        $journey = Journey::query()->where('uuid', $uuid)->firstOrFail();

        if ($journey->status !== 'active') {
            return $request->expectsJson()
                ? response()->json(['message' => __('ventmail.journeys.notActive')], 422)
                : back()->with('error', __('ventmail.journeys.notActive'));
        }

        $journey->forceFill(['status' => 'paused'])->save();

        $audit->log('journey.paused', $journey, context: ['version' => $journey->currentVersion?->number]);

        return $request->expectsJson()
            ? response()->json(['message' => __('ventmail.journeys.paused'), 'status' => 'paused'])
            : back()->with('success', __('ventmail.journeys.paused'));
    }
}
