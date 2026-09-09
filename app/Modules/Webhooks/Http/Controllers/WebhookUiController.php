<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Controllers;

use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * WEBHOOK-VÉGPONTOK ÉS KÉZBESÍTÉSEK képernyője (28. kritérium).
 *
 * ## Miért egy képernyőn
 *
 * Mert a kérdés, amiért idejön valaki, egyben van: „miért nem kapom meg az eseményeket?"
 * A válasz vagy a végpontnál van (kikapcsolt, rossz URL, szűk esemény-lista), vagy a
 * kézbesítéseknél (4xx, timeout, dead-letter). Két külön képernyőn az összefüggést a
 * felhasználónak kellene összeraknia.
 *
 * ## A DEAD-LETTER csak akkor ér valamit, ha van belőle kivezető út
 *
 * Ezért van újrajátszás gomb: az ügyfél megjavítja a végpontot, és újraküldeti, ami nem
 * jutott el. A replay a kísérlet-számlálót NEM nullázza — a trace-nek meg kell mutatnia,
 * hányadik körnél jártunk.
 */
final class WebhookUiController
{
    /** UGYANAZ a lista, mint a `/v1` végponton — két listából előbb-utóbb kettő lesz. */
    private const TYPES = [
        'accepted', 'rejected', 'delivered', 'deferred', 'bounced',
        'complained', 'unsubscribed', 'opened', 'clicked', 'stored',
    ];

    public function index(Request $request): Response
    {
        Gate::authorize('webhook.manage');

        $status = $request->string('status')->toString();

        $deliveries = WebhookDelivery::query()
            ->with('endpoint')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return Inertia::render('Webhooks/Index', [
            'status' => $status,
            'endpoints' => WebhookEndpoint::query()->orderBy('name')->get()->map(fn (WebhookEndpoint $endpoint) => [
                'uuid' => (string) $endpoint->uuid,
                'name' => (string) $endpoint->name,
                'url' => (string) $endpoint->url,
                // ÜRES lista = minden esemény. A felületnek ezt ki kell írnia, mert a
                // hiányzó érték „semmi"-nek látszana.
                'events' => array_values((array) $endpoint->events),
                'active' => (bool) $endpoint->active,
                'lastSuccessAt' => $endpoint->last_success_at?->toIso8601String(),
            ])->values()->all(),
            'deliveries' => $deliveries->map(fn (WebhookDelivery $delivery) => [
                'uuid' => (string) $delivery->uuid,
                'endpoint' => (string) ($delivery->endpoint?->name ?? '—'),
                'event' => (string) $delivery->event_type,
                'status' => (string) $delivery->status,
                'attempt' => (int) $delivery->attempt,
                'responseCode' => $delivery->response_code === null ? null : (int) $delivery->response_code,
                'nextAttemptAt' => $delivery->next_attempt_at?->toIso8601String(),
                'deadLetteredAt' => $delivery->dead_lettered_at?->toIso8601String(),
                'createdAt' => $delivery->created_at?->toIso8601String(),
                // A SIKERES kézbesítést nincs értelme újrajátszani — a gomb hiánya
                // megelőzi a „miért nem történt semmi?" kérdést.
                'replayable' => $delivery->status !== 'delivered',
            ])->values()->all(),
            'availableEvents' => self::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('webhook.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:2048'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', Rule::in(self::TYPES)],
        ]);

        try {
            $issued = WebhookEndpoint::issue(
                $validated['name'],
                $validated['url'],
                array_values($validated['events'] ?? []),
            );
        } catch (InvalidArgumentException $e) {
            // SSRF-elutasítás: a hibát OTT jelezzük, ahol az ügyfél beírja a címet, nem a
            // hatodik sikertelen kézbesítésnél.
            return back()->with('error', $e->getMessage());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // A TITOK EGYSZER látszik — ugyanaz az érv, mint az API-kulcsnál.
        return back()->with('secret', ['kind' => 'webhook', 'value' => $issued['secret']]);
    }

    public function destroy(string $uuid): RedirectResponse
    {
        Gate::authorize('webhook.manage');

        WebhookEndpoint::query()->where('uuid', $uuid)->firstOrFail()->delete();

        return back()->with('success', __('ventmail.webhooks.deleted'));
    }

    public function replay(string $uuid): RedirectResponse
    {
        Gate::authorize('webhook.manage');

        $delivery = WebhookDelivery::query()->where('uuid', $uuid)->firstOrFail();

        if ($delivery->status === 'delivered') {
            return back()->with('error', __('ventmail.webhooks.alreadyDelivered'));
        }

        // UGYANAZ az állapot-átmenet, mint a `/v1` replay-en: `failed` + azonnali esedékesség.
        // A kísérlet-számláló marad — a trace-nek meg kell mutatnia, hányadik körnél jártunk.
        $delivery->forceFill(['status' => 'failed', 'next_attempt_at' => now()])->save();

        return back()->with('success', __('ventmail.webhooks.replayed'));
    }
}
