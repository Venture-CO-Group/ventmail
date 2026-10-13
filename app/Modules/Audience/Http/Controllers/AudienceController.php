<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Controllers;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\AudienceInsights;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Consent\Models\Consent;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\QueryBudget;
use App\Support\QueryTooExpensive;
use App\Support\WorkspaceTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * KÖZÖNSÉG képernyője (49–52. kritérium): listák, kapcsolatok, szegmensek.
 *
 * ## A CONSENT ÁLLAPOTA a kapcsolat mellett van, nem külön képernyőn
 *
 * Mert a marketing küldés jogalapja EZ: aki nincs megerősítve, annak a `ConsentGate`
 * (3. kapu) nem enged marketinget. Ha a consent egy másik képernyőn lakna, a felhasználó
 * kiküldene egy kampányt, és utána keresné, miért esett ki a fele.
 *
 * ## A CÍMEK LAPOZVA jönnek
 *
 * A kapcsolat-tábla százezres nagyságrendű, és minden sor személyes adat. A teljes
 * betöltés memóriára futna, és egy képernyőnyi „véletlen" adatot öntene ki.
 */
final class AudienceController
{
    public function index(Request $request, SegmentEngine $segments): Response
    {
        Gate::authorize('audience.manage');

        $search = $request->string('search')->toString();

        $contacts = Contact::query()
            // v1.1 · a CÍMKÉK a sor mellett (egy eager load a lapra, nem soronként).
            ->with('tags:contact_tags.id,contact_tags.uuid,contact_tags.name')
            ->when($search !== '', fn ($query) => $query->where(
                'email',
                'like',
                '%'.addcslashes(mb_strtolower(trim($search)), '%_\\').'%'
            ))
            ->orderByDesc('id')
            ->cursorPaginate(50, ['*'], 'cursor', $request->string('cursor')->toString() ?: null);

        // A CONSENT-ÁLLAPOT egyetlen lekérdezéssel, a lapon szereplő címekre — nem
        // soronként (az N+1 egy 50 soros lapon 50 kör lenne).
        $emails = collect($contacts->items())->map(fn (Contact $contact) => (string) $contact->email)->all();

        $consents = Consent::query()
            ->whereIn('email', $emails)
            ->where('channel', 'marketing')
            ->get()
            ->keyBy(fn (Consent $consent) => (string) $consent->email);

        return Inertia::render('Audience/Index', [
            'search' => $search,
            'lists' => ContactList::query()->orderBy('name')->get()->map(fn (ContactList $list) => [
                'uuid' => (string) $list->uuid,
                'name' => (string) $list->name,
                'description' => $list->description,
                'fromEmail' => $list->from_email,
                'fromName' => $list->from_name,
                'contacts' => $list->contacts()->count(),
            ])->values()->all(),

            // v1.1 · a MEZŐKEZELŐ: az egyéni mezők, preference-center láthatósággal (review B1).
            'fields' => ContactField::query()->orderBy('label')->get(['uuid', 'key', 'label', 'type', 'preference_visible'])
                ->map(fn (ContactField $field) => [
                    'uuid' => (string) $field->uuid,
                    'key' => (string) $field->key,
                    'label' => (string) $field->label,
                    'type' => (string) $field->type,
                    'preferenceVisible' => (bool) $field->preference_visible,
                ])->values()->all(),

            // v1.1 · a tömeges címkézés választója.
            'tags' => ContactTag::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (ContactTag $tag) => ['uuid' => (string) $tag->uuid, 'name' => (string) $tag->name])
                ->values()->all(),

            'segments' => Segment::query()->orderBy('name')->get()->map(fn (Segment $segment) => [
                'uuid' => (string) $segment->uuid,
                'name' => (string) $segment->name,
                'description' => $segment->description,
                // A BECSÜLT MÉRET a legutóbbi számításból: a szegmens definíció szerint
                // él, a mérete pedig azonnal elavul — ezért „becsült", és ezért látszik a
                // számítás ideje is.
                'estimatedSize' => $segment->estimated_size === null ? null : (int) $segment->estimated_size,
                'estimatedAt' => $segment->estimated_at?->toIso8601String(),
            ])->values()->all(),

            'contacts' => [
                'items' => collect($contacts->items())->map(function (Contact $contact) use ($consents) {
                    $consent = $consents->get((string) $contact->email);

                    return [
                        'uuid' => (string) $contact->uuid,
                        'email' => (string) $contact->email,
                        'name' => trim(((string) $contact->first_name).' '.((string) $contact->last_name)),
                        'status' => (string) $contact->status,
                        // A MARKETING JOGALAP: enélkül a kampány fele csendben kiesne a
                        // 3. kapuban, és a felhasználó a felületen keresné az okot.
                        'consent' => $consent === null ? 'none' : (string) $consent->status,
                        'validation' => $contact->validation_verdict,
                        'createdAt' => $contact->created_at?->toIso8601String(),
                        'source' => $contact->source,
                        'tags' => $contact->tags->map(fn (ContactTag $tag) => (string) $tag->name)->values()->all(),
                    ];
                })->values()->all(),
                'nextCursor' => $contacts->nextCursor()?->encode(),
            ],
        ]);
    }

    /**
     * KÖZÖNSÉG-DASHBOARD adatai (v1.1 · 5. modul) — JSON, chart-könyvtártól független alak.
     *
     * Külön végpont, nem Inertia-prop: a kontaktlista lapozása (cursor) ne számolja újra a
     * három aggregátumot, és a 8. modul grafikonjai ugyanezt a végpontot olvassák.
     */
    public function insights(Request $request, AudienceInsights $insights, WorkspaceContext $context): JsonResponse
    {
        Gate::authorize('audience.manage');

        try {
            // A KERET (review S2) — 5 s; a gyorsítótár (RS5) miatt ez ritkán fut.
            $summary = app(QueryBudget::class)->run(fn () => $insights->summary(
                (int) $request->integer('days', 30),
                WorkspaceTimezone::for($context->workspace(), $request->user()),
            ));
        } catch (QueryTooExpensive) {
            return response()->json(['message' => __('studio.insights.tooExpensive')], 422);
        }

        return response()->json($summary);
    }

    /**
     * MEZŐKEZELŐ (review B1): az egyéni mező látszik-e és írható-e a preference centerben.
     *
     * Alapból NEM: a /p/{token} link továbbítható (és 90 napig él), tehát egy mező csak akkor
     * kerülhet a címzett elé, ha a tenant kifejezetten így döntött. Auditált (`updated`).
     */
    public function fieldPreference(Request $request, string $uuid): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $field = ContactField::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate(['visible' => ['required', 'boolean']]);

        $field->forceFill(['preference_visible' => (bool) $validated['visible']])->save();

        return back()->with('success', __('studio.fields.updated'));
    }

    public function storeList(Request $request): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'fromEmail' => ['nullable', 'string', 'email', 'max:320'],
            'fromName' => ['nullable', 'string', 'max:120'],
        ]);

        ContactList::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'from_email' => $validated['fromEmail'] ?? null,
            'from_name' => $validated['fromName'] ?? null,
        ]);

        return back()->with('success', __('ventmail.audience.listCreated'));
    }

    /**
     * LISTA SZERKESZTÉSE.
     *
     | A NÉV ÉS A LEÍRÁS a CÍMZETTNEK is megjelenik: a preference center a kontakt saját
     | listáit sorolja fel, névvel. Szerkesztés nélkül egy elgépelt név örökre ott maradt
     | volna a leiratkozó felületen — javítani csak adatbázisból lehetett.
     */
    public function updateList(Request $request, string $uuid): RedirectResponse
    {
        Gate::authorize('audience.manage');

        $list = ContactList::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'fromEmail' => ['nullable', 'string', 'email', 'max:320'],
            'fromName' => ['nullable', 'string', 'max:120'],
        ]);

        $list->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'from_email' => $validated['fromEmail'] ?? null,
            'from_name' => $validated['fromName'] ?? null,
        ])->save();

        return back()->with('success', __('ventmail.audience.listUpdated'));
    }
}
