<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Controllers;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\ConsentEvent;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A KONTAKT ADATLAPJA: mezők, listák, címkék, a marketing-hozzájárulás állapota és a
 * hozzájárulás TÖRTÉNETE — köztük az import forrásával és jogalapjával.
 *
 * A történet a `consent_events` táblából jön (a bizonyíték), az email_hash-en át: a
 * bizonyíték-tábla nyers címet nem tárol. Az esemény `payload`-ja hordozza az import
 * fájlnevét, így a „honnan került ez a cím ide?" kérdésre az adatlap válaszol.
 */
final class ContactController
{
    public function show(string $uuid): Response
    {
        Gate::authorize('audience.manage');

        // A global scope miatt idegen workspace kontaktja 404.
        $contact = Contact::query()->where('uuid', $uuid)->firstOrFail();

        $labels = ContactField::query()->pluck('label', 'key');

        $consent = Consent::query()->where('email', $contact->email)->where('channel', 'marketing')->first();

        $history = ConsentEvent::query()
            ->where('email_hash', $contact->email_hash)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return Inertia::render('Audience/ContactShow', [
            'contact' => [
                'uuid' => (string) $contact->uuid,
                'email' => (string) $contact->email,
                'firstName' => $contact->first_name,
                'lastName' => $contact->last_name,
                'status' => (string) $contact->status,
                'validation' => $contact->validation_verdict,
                'subscribedAt' => $contact->subscribed_at?->toIso8601String(),
                'createdAt' => $contact->created_at?->toIso8601String(),
                'attributes' => collect((array) $contact->attributes)
                    ->map(static fn (mixed $value, string|int $key): array => [
                        'key' => (string) $key,
                        'label' => (string) ($labels[$key] ?? $key),
                        'value' => is_bool($value) ? ($value ? '1' : '0') : (is_scalar($value) ? (string) $value : json_encode($value)),
                    ])->values()->all(),
            ],
            'lists' => $contact->lists()->orderBy('name')->get()->map(static fn ($list): array => [
                'uuid' => (string) $list->uuid,
                'name' => (string) $list->name,
                'status' => (string) $list->pivot->status,
            ])->values()->all(),
            'tags' => $contact->tags()->orderBy('name')->get()->map(static fn (ContactTag $tag): string => (string) $tag->name)->values()->all(),
            'consent' => $consent === null ? null : [
                'status' => (string) $consent->status,
                'legalBasis' => (string) $consent->legal_basis,
                'source' => $consent->source,
                'confirmedAt' => $consent->confirmed_at?->toIso8601String(),
                'revokedAt' => $consent->revoked_at?->toIso8601String(),
            ],
            'history' => $history->map(static fn (ConsentEvent $event): array => [
                'uuid' => (string) $event->uuid,
                'type' => (string) $event->type,
                'legalBasis' => (string) $event->legal_basis,
                'source' => $event->source,
                'detail' => $event->form_text,
                'importFile' => $event->type === 'imported' ? ($event->payload['file'] ?? null) : null,
                'resultingStatus' => $event->type === 'imported' ? ($event->payload['resulting_status'] ?? null) : null,
                'occurredAt' => $event->occurred_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }
}
