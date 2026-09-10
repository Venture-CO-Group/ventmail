<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Controllers;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Consent\Models\Consent;
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
                    ];
                })->values()->all(),
                'nextCursor' => $contacts->nextCursor()?->encode(),
            ],
        ]);
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
