<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Controllers;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Audience\Services\TagOperations;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * CÍMKÉK (v1.1 · 5. modul) — kezelés és tömeges címkézés.
 *
 * A tömeges művelet célpontja HÁROMFÉLE lehet, és mindhárom egy KONTAKT-lekérdezés (a global
 * scope-pal): a kijelölt kontaktok (legfeljebb 1000 uuid), a keresés minden találata, vagy egy
 * mentett szegmens találatai. Más célpont nincs — az engedélylista a validációban él.
 */
final class TagController
{
    /** A kijelölés felső határa — a „minden találat" a keresés/szegmens célpont. */
    private const MAX_SELECTION = 1000;

    public function index(): Response
    {
        Gate::authorize('audience.manage');

        return Inertia::render('Audience/Tags', [
            // A DARABSZÁM egy csoportosított allekérdezésből (nem címkénkénti `count()`).
            'tags' => ContactTag::query()
                ->withCount('contacts')
                ->orderBy('name')
                ->get()
                ->map(fn (ContactTag $tag) => [
                    'uuid' => (string) $tag->uuid,
                    'name' => (string) $tag->name,
                    'contacts' => (int) $tag->contacts_count,
                    'createdAt' => $tag->created_at?->toIso8601String(),
                ])->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('audience.manage');

        ContactTag::create(['name' => $this->validatedName($request, null)]);

        return back()->with('success', __('studio.tags.created'));
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $tag = ContactTag::query()->where('uuid', $uuid)->firstOrFail();
        $tag->name = $this->validatedName($request, $tag);
        $tag->save();

        return back()->with('success', __('studio.tags.renamed'));
    }

    public function merge(Request $request, string $uuid, TagOperations $operations): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $from = ContactTag::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate(['into' => ['required', 'string', 'uuid', Rule::notIn([$uuid])]]);

        // A cél is a global scope-on át: idegen workspace címkéjébe nem lehet összevonni.
        $into = ContactTag::query()->where('uuid', $validated['into'])->firstOrFail();

        $journeys = $operations->journeyReferences($from);

        if ($journeys !== []) {
            return back()->with('error', __('studio.tags.usedByJourney', ['names' => implode(', ', $journeys)]));
        }

        $moved = $operations->merge($from, $into);

        return back()->with('success', __('studio.tags.merged', ['count' => $moved, 'name' => $into->name]));
    }

    /**
     * TÖRLÉS — a hivatkozott címke nem.
     *
     * Egy szegmens `not_has_tag` szabálya a törölt címkére MINDENKIT beválasztana; egy
     * journey `add_tag` lépése hibára futna. A felhasználó előbb a hivatkozást szünteti meg
     * (vagy összevon).
     */
    public function destroy(string $uuid, TagOperations $operations): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $tag = ContactTag::query()->where('uuid', $uuid)->firstOrFail();

        $segments = array_map(static fn (Segment $segment) => (string) $segment->name, $operations->segmentReferences($tag));
        $journeys = $operations->journeyReferences($tag);

        if ($segments !== [] || $journeys !== []) {
            return back()->with('error', __('studio.tags.inUse', ['names' => implode(', ', [...$segments, ...$journeys])]));
        }

        $tag->delete();

        return back()->with('success', __('studio.tags.deleted'));
    }

    /**
     * TÖMEGES címkézés / levétel.
     *
     * A címke vagy létező (`tagUuid`), vagy a művelettel együtt jön létre (`tagName`) — a
     * „címkézd meg ezeket egy új címkével" a leggyakoribb eset.
     */
    public function bulk(Request $request, TagOperations $operations, SegmentEngine $segments): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $validated = $request->validate([
            'action' => ['required', Rule::in(['tag', 'untag'])],
            'tagUuid' => ['nullable', 'required_without:tagName', 'string', 'uuid'],
            'tagName' => ['nullable', 'required_without:tagUuid', 'string', 'max:120'],
            'target' => ['required', 'array'],
            'target.type' => ['required', Rule::in(['contacts', 'search', 'segment'])],
            'target.uuids' => ['required_if:target.type,contacts', 'array', 'max:'.self::MAX_SELECTION],
            'target.uuids.*' => ['string', 'uuid'],
            'target.search' => ['nullable', 'string', 'max:200'],
            'target.uuid' => ['required_if:target.type,segment', 'nullable', 'string', 'uuid'],
        ]);

        $contacts = $this->targetQuery($validated['target'], $segments);

        return DB::transaction(function () use ($validated, $contacts, $operations): RedirectResponse {
            $tag = $this->resolveTag($validated);
            $target = (string) $validated['target']['type'];

            $affected = $validated['action'] === 'tag'
                ? $operations->assign($tag, $contacts, $target)
                : $operations->remove($tag, $contacts, $target);

            return back()->with('success', __(
                $validated['action'] === 'tag' ? 'studio.tags.bulkTagged' : 'studio.tags.bulkUntagged',
                ['count' => $affected, 'name' => $tag->name],
            ));
        });
    }

    /**
     * @param  array<string, mixed>  $target
     * @return Builder<Contact>
     */
    private function targetQuery(array $target, SegmentEngine $segments): Builder
    {
        return match ($target['type']) {
            'contacts' => Contact::query()->whereIn('uuid', array_values((array) $target['uuids'])),
            // UGYANAZ a keresés, mint a kontaktlistán (`AudienceController::index`).
            'search' => Contact::query()->when(
                trim((string) ($target['search'] ?? '')) !== '',
                fn (Builder $query) => $query->where('email', 'like', '%'.addcslashes(mb_strtolower(trim((string) $target['search'])), '%_\\').'%'),
            ),
            'segment' => $this->segmentQuery((string) $target['uuid'], $segments),
        };
    }

    /** @return Builder<Contact> */
    private function segmentQuery(string $uuid, SegmentEngine $segments): Builder
    {
        $segment = Segment::query()->where('uuid', $uuid)->firstOrFail();

        try {
            return $segments->query((array) $segment->definition);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['target' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $validated */
    private function resolveTag(array $validated): ContactTag
    {
        if (! empty($validated['tagUuid'])) {
            return ContactTag::query()->where('uuid', $validated['tagUuid'])->firstOrFail();
        }

        $name = trim((string) $validated['tagName']);

        if ($name === '') {
            throw ValidationException::withMessages(['tagName' => __('studio.tags.nameRequired')]);
        }

        return ContactTag::query()->firstOrCreate(['name' => $name]);
    }

    private function validatedName(Request $request, ?ContactTag $tag): string
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('contact_tags', 'name')
                    ->where('workspace_id', app(WorkspaceContext::class)->workspaceId())
                    ->ignore($tag?->getKey()),
            ],
        ]);

        return trim((string) $validated['name']);
    }
}
