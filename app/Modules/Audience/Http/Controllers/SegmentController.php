<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Controllers;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\QueryBudget;
use App\Support\QueryTooExpensive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * SZEGMENSÉPÍTŐ (v1.1 · 5. modul) — lista, vizuális szerkesztő, élő előnézet, mentés.
 *
 * ## Egy motor, három fogyasztó
 *
 * Az előnézet, a kampány-célzás (`AudienceResolver`) és a journey (`segment_entered`)
 * UGYANAZT a `SegmentEngine::query()`-t hívja. A kontroller SOHA nem épít saját feltételt:
 * a definíció JSON változatlanul megy a motornak, ami a mezőket és az operátorokat
 * engedélylistáról oldja fel, és minden ismeretlent HANGOSAN elutasít (422).
 *
 * ## A mentett definíció = az előnézett definíció
 *
 * A mentés előtt a motor újra felépíti a lekérdezést (validálás) és megszámolja — a tárolt
 * `estimated_size` így a mentés pillanatának valódi mérete.
 */
final class SegmentController
{
    /** Az előnézet mintája: ennyi sort mutatunk a feltételek mellett. */
    private const SAMPLE = 10;

    public function index(): Response
    {
        Gate::authorize('audience.manage');

        // A HASZNÁLAT (melyik kampány célozza) egyetlen lekérdezéssel: a szegmens törlése
        // előtt látszania kell, hogy kampány hivatkozik rá.
        $usage = $this->campaignUsage();

        return Inertia::render('Audience/Segments', [
            'segments' => Segment::query()->orderBy('name')->get()->map(fn (Segment $segment) => [
                'uuid' => (string) $segment->uuid,
                'name' => (string) $segment->name,
                'description' => $segment->description,
                'estimatedSize' => $segment->estimated_size === null ? null : (int) $segment->estimated_size,
                'estimatedAt' => $segment->estimated_at?->toIso8601String(),
                'groups' => $this->groupCount((array) $segment->definition),
                'campaigns' => $usage[(string) $segment->uuid] ?? 0,
            ])->values()->all(),
        ]);
    }

    public function create(SegmentEngine $engine): Response
    {
        Gate::authorize('audience.manage');

        return $this->builder($engine, null);
    }

    public function show(string $uuid, SegmentEngine $engine): Response
    {
        Gate::authorize('audience.manage');

        return $this->builder($engine, Segment::query()->where('uuid', $uuid)->firstOrFail());
    }

    /**
     * ÉLŐ ELŐNÉZET — szám + minta, mentés nélkül.
     *
     * JSON-végpont (a szerkesztő debounce-szal hívja); a hibás definíció 422 a motor
     * üzenetével — sosem 500 és sosem csendes „0 találat".
     */
    public function preview(Request $request, SegmentEngine $engine): JsonResponse
    {
        Gate::authorize('audience.manage');

        $definition = $this->definition($request);

        try {
            // A KERET (review S2): a felhasználó által összerakott lekérdezés legfeljebb 5 s.
            [$count, $sample] = app(QueryBudget::class)->run(function () use ($engine, $definition): array {
                $query = $engine->query($definition);

                return [(clone $query)->count(), $query->limit(self::SAMPLE)->get(['uuid', 'email', 'first_name', 'last_name', 'status'])];
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['definition' => $e->getMessage()]);
        } catch (QueryTooExpensive) {
            throw ValidationException::withMessages(['definition' => __('studio.segments.tooExpensive')]);
        }

        return response()->json([
            'count' => $count,
            'sample' => $sample->map(fn (Contact $contact) => [
                'uuid' => (string) $contact->uuid,
                'email' => (string) $contact->email,
                'name' => trim(((string) $contact->first_name).' '.((string) $contact->last_name)),
                'status' => (string) $contact->status,
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, SegmentEngine $engine): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $validated = $this->validated($request, null);
        $size = $this->measure($engine, $validated['definition']);

        $segment = Segment::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'definition' => $validated['definition'],
            'estimated_size' => $size,
            'estimated_at' => now(),
        ]);

        return redirect()->route('audience.segments.show', ['uuid' => $segment->uuid])
            ->with('success', __('studio.segments.saved'));
    }

    public function update(Request $request, string $uuid, SegmentEngine $engine): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $segment = Segment::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $this->validated($request, $segment);
        $size = $this->measure($engine, $validated['definition']);

        $segment->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'definition' => $validated['definition'],
            'estimated_size' => $size,
            'estimated_at' => now(),
        ])->save();

        return back()->with('success', __('studio.segments.saved'));
    }

    /**
     * TÖRLÉS — de nem, ha egy még ki nem ment kampány célozza.
     *
     * Egy ütemezett kampány célzásából eltűnő szegmens a küldés pillanatában egyszerűen
     * kimaradna (a feloldó a nem létező uuid-t átugorja) — a kampány kisebb közönségnek
     * menne ki, mint amit a felhasználó jóváhagyott.
     */
    public function destroy(string $uuid): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $segment = Segment::query()->where('uuid', $uuid)->firstOrFail();

        if (($this->campaignUsage()[(string) $segment->uuid] ?? 0) > 0) {
            return back()->with('error', __('studio.segments.inUse'));
        }

        $segment->delete();

        return redirect()->route('audience.segments.index')->with('success', __('studio.segments.deleted'));
    }

    private function builder(SegmentEngine $engine, ?Segment $segment): Response
    {
        return Inertia::render('Audience/SegmentBuilder', [
            'segment' => $segment === null ? null : [
                'uuid' => (string) $segment->uuid,
                'name' => (string) $segment->name,
                'description' => $segment->description,
                'definition' => $segment->definition,
                'estimatedSize' => $segment->estimated_size === null ? null : (int) $segment->estimated_size,
            ],
            // A KATALÓGUS a szerverről: mezők, típusok, operátorok, a tenant egyéni mezőivel.
            'catalog' => $engine->catalog(),
            'lists' => ContactList::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (ContactList $list) => ['uuid' => (string) $list->uuid, 'name' => (string) $list->name])->values()->all(),
            'tags' => ContactTag::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (ContactTag $tag) => ['uuid' => (string) $tag->uuid, 'name' => (string) $tag->name])->values()->all(),
        ]);
    }

    /** @return array{name: string, description: ?string, definition: array<string, mixed>} */
    private function validated(Request $request, ?Segment $segment): array
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                // A NÉV tenantonként egyedi (DB-index) — a felület mondja meg, ne a 500.
                Rule::unique('segments', 'name')
                    ->where('workspace_id', app(WorkspaceContext::class)->workspaceId())
                    ->whereNull('deleted_at')
                    ->ignore($segment?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'definition' => ['required', 'array'],
        ]);

        return [
            'name' => (string) $validated['name'],
            'description' => $validated['description'] ?? null,
            'definition' => $this->definition($request),
        ];
    }

    /** @return array<string, mixed> */
    private function definition(Request $request): array
    {
        $definition = $request->input('definition');

        if (! is_array($definition)) {
            throw ValidationException::withMessages(['definition' => __('studio.segments.invalid')]);
        }

        return $this->normalise($definition);
    }

    /**
     * A MENTETT alak egységesítése (review NIT): az illesztési mód és az operátor kisbetűs, a
     * uuid-értékek (lista, címke) kisbetűsek. A motor eddig is kis/nagybetű-függetlenül
     * értelmezte őket, de a hivatkozás-őrök (címke törlése / összevonása) a tárolt szövegben
     * keresnek — egy nagybetűs uuid ott láthatatlan maradna.
     *
     * @param  array<mixed>  $group
     * @return array<mixed>
     */
    private function normalise(array $group): array
    {
        if (isset($group['match']) && is_string($group['match'])) {
            $group['match'] = strtolower($group['match']);
        }

        if (isset($group['rules']) && is_array($group['rules'])) {
            $group['rules'] = array_map(function ($rule) {
                if (! is_array($rule)) {
                    return $rule;
                }

                if (isset($rule['group']) && is_array($rule['group'])) {
                    $rule['group'] = $this->normalise($rule['group']);

                    return $rule;
                }

                if (isset($rule['op']) && is_string($rule['op'])) {
                    $rule['op'] = strtolower($rule['op']);
                }

                if (isset($rule['value']) && is_string($rule['value']) && Str::isUuid($rule['value'])) {
                    $rule['value'] = strtolower($rule['value']);
                }

                return $rule;
            }, $group['rules']);
        }

        return $group;
    }

    /** @param array<string, mixed> $definition */
    private function measure(SegmentEngine $engine, array $definition): int
    {
        try {
            return app(QueryBudget::class)->run(fn () => $engine->count($definition));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['definition' => $e->getMessage()]);
        } catch (QueryTooExpensive) {
            throw ValidationException::withMessages(['definition' => __('studio.segments.tooExpensive')]);
        }
    }

    /**
     * Mely szegmenseket célozza (vagy zárja ki) egy még ki nem ment kampány?
     *
     * @return array<string, int> szegmens-uuid → kampányok száma
     */
    private function campaignUsage(): array
    {
        $usage = [];

        Campaign::query()
            ->whereIn('status', ['draft', 'scheduled', 'sending', 'paused'])
            ->get(['target_segments', 'exclude_segments'])
            ->each(function (Campaign $campaign) use (&$usage): void {
                foreach (array_unique([...(array) $campaign->target_segments, ...(array) $campaign->exclude_segments]) as $uuid) {
                    if (is_scalar($uuid)) {
                        $usage[(string) $uuid] = ($usage[(string) $uuid] ?? 0) + 1;
                    }
                }
            });

        return $usage;
    }

    /** @param array<string, mixed> $definition */
    private function groupCount(array $definition): int
    {
        return count(array_filter((array) ($definition['rules'] ?? []), static fn ($rule) => is_array($rule) && isset($rule['group'])));
    }
}
