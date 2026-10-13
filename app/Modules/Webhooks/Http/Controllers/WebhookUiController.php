<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Controllers;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
        // v1.1 · 6. modul (review SEC-S2): a journey webhook-csomópontja csak az erre
        // feliratkozott végpontnak küld. (A `/v1` szerződés eseménylistája változatlan.)
        'journey.step',
    ];

    /** A felületről indítható teszt-események — a `/v1` szerződés hat ügyfél-eseménye. */
    public const TEST_TYPES = ['delivered', 'opened', 'clicked', 'bounced', 'complained', 'unsubscribed'];

    /** A rotációs türelmi ablak (óra), ha a felhasználó kéri. */
    public const GRACE_HOURS = 24;

    /** Teszt-esemény plafon workspace-enként percenként (a cél-URL nem válhat letapogató eszközzé). */
    private const TEST_PER_MINUTE = 10;

    /** A kézbesítési napló hossza (rules/40: „utolsó 100 kézbesítés"). */
    private const LOG_LIMIT = 100;

    public function index(Request $request): Response
    {
        Gate::authorize('webhook.manage');

        $status = $request->string('status')->toString();
        $status = in_array($status, ['delivered', 'failed', 'dead_letter', 'pending'], true) ? $status : '';
        $endpointUuid = $request->string('endpoint')->toString();

        $endpoints = WebhookEndpoint::query()->orderBy('name')->limit(200)->get();
        $endpointFilter = $endpointUuid !== '' ? $endpoints->firstWhere('uuid', $endpointUuid) : null;

        $deliveries = WebhookDelivery::query()
            ->with('endpoint')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($endpointFilter !== null, fn ($query) => $query->where('webhook_endpoint_id', $endpointFilter->getKey()))
            ->orderByDesc('id')
            ->limit(self::LOG_LIMIT)
            ->get();

        // Végpontonkénti arány az elmúlt 7 napból — EGY csoportosító lekérdezés, indexelt
        // (`workspace_id`, `status`) szűrővel, nem végpontonként külön.
        $stats = WebhookDelivery::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('webhook_endpoint_id, count(*) as total, count(*) filter (where status = ?) as ok, count(*) filter (where status in (?, ?)) as bad', ['delivered', 'failed', 'dead_letter'])
            ->groupBy('webhook_endpoint_id')
            ->get()
            ->keyBy('webhook_endpoint_id');

        return Inertia::render('Webhooks/Index', [
            'status' => $status,
            'endpointFilter' => $endpointFilter?->uuid,
            'endpoints' => $endpoints->map(fn (WebhookEndpoint $endpoint) => [
                'uuid' => (string) $endpoint->uuid,
                'name' => (string) $endpoint->name,
                'url' => (string) $endpoint->url,
                // ÜRES lista = minden esemény. A felületnek ezt ki kell írnia, mert a
                // hiányzó érték „semmi"-nek látszana.
                'events' => array_values((array) $endpoint->events),
                'active' => (bool) $endpoint->active,
                'lastSuccessAt' => $endpoint->last_success_at?->toIso8601String(),
                'lastFailureAt' => $endpoint->last_failure_at?->toIso8601String(),
                // A titokból CSAK az utolsó négy karakter: ebből ismerhető fel, melyik él.
                'secretHint' => $endpoint->secretHint(),
                'previousSecretUntil' => $endpoint->previous_secret_expires_at?->isFuture() === true
                    ? $endpoint->previous_secret_expires_at->toIso8601String()
                    : null,
                'total7d' => (int) ($stats[$endpoint->getKey()]->total ?? 0),
                'ok7d' => (int) ($stats[$endpoint->getKey()]->ok ?? 0),
                'failed7d' => (int) ($stats[$endpoint->getKey()]->bad ?? 0),
            ])->values()->all(),
            'deliveries' => $deliveries->map(fn (WebhookDelivery $delivery) => [
                'uuid' => (string) $delivery->uuid,
                'endpoint' => (string) ($delivery->endpoint?->name ?? '—'),
                'event' => (string) $delivery->event_type,
                'status' => (string) $delivery->status,
                'attempt' => (int) $delivery->attempt,
                'responseCode' => $delivery->response_code === null ? null : (int) $delivery->response_code,
                'responseExcerpt' => $delivery->response_excerpt === null ? null : mb_substr((string) $delivery->response_excerpt, 0, 160),
                'durationMs' => $delivery->duration_ms === null ? null : (int) $delivery->duration_ms,
                'isTest' => (bool) $delivery->is_test,
                'nextAttemptAt' => $delivery->next_attempt_at?->toIso8601String(),
                'deadLetteredAt' => $delivery->dead_lettered_at?->toIso8601String(),
                'createdAt' => $delivery->created_at?->toIso8601String(),
                // A SIKERES kézbesítést nincs értelme újrajátszani — a gomb hiánya
                // megelőzi a „miért nem történt semmi?" kérdést.
                'replayable' => $delivery->status !== 'delivered',
            ])->values()->all(),
            'availableEvents' => self::TYPES,
            'testEvents' => self::TEST_TYPES,
            'graceHours' => self::GRACE_HOURS,
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

    /** Eseménytípusok és aktív állapot módosítása (auditált: `Auditable`). */
    public function update(Request $request, string $uuid): RedirectResponse
    {
        Gate::authorize('webhook.manage');

        $endpoint = WebhookEndpoint::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'events' => ['sometimes', 'array'],
            'events.*' => ['string', Rule::in(self::TYPES)],
            'active' => ['sometimes', 'boolean'],
        ]);

        $endpoint->fill($validated)->save();

        return back()->with('success', __('ventmail.plat.webhookUpdated'));
    }

    /**
     * Titok-rotáció a felületről (D171). Az új titok EGYSZER látszik (flash); `grace = true`
     * esetén a régi még {@see GRACE_HOURS} óráig a második aláírásban szerepel.
     */
    public function rotate(Request $request, string $uuid, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('webhook.manage');

        $endpoint = WebhookEndpoint::query()->where('uuid', $uuid)->firstOrFail();
        $grace = $request->boolean('grace');

        $secret = $endpoint->rotateSecret($grace ? self::GRACE_HOURS : 0);

        $audit->log('webhook.secret_rotated', $endpoint, context: ['grace_hours' => $grace ? self::GRACE_HOURS : 0]);

        return back()->with('secret', ['kind' => 'webhook', 'value' => $secret]);
    }

    /**
     * TESZT-ESEMÉNY (D172): egy szintetikus, `test: true` jelölésű payload a végpontra.
     *
     * LEVÉL NEM MEGY: nincs `Message`, nincs `MessageEvent`, a küldőmotor érintetlen — csak
     * egy `webhook_deliveries` sor, amit UGYANAZ a `DeliverWebhook` kézbesít (SSRF-őr,
     * IP-pinning, aláírás, retry, dead-letter). A címzett a fenntartott `example.com`.
     */
    public function test(Request $request, string $uuid, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('webhook.manage');

        $endpoint = WebhookEndpoint::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate(['event' => ['required', 'string', Rule::in(self::TEST_TYPES)]]);

        $key = 'webhook-test:'.$endpoint->workspace_id;

        if (RateLimiter::tooManyAttempts($key, self::TEST_PER_MINUTE)) {
            return back()->with('error', __('ventmail.plat.webhookTestThrottled'));
        }

        RateLimiter::hit($key, 60);

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event_type' => $validated['event'],
            'message_uuid' => null,
            'payload' => self::samplePayload($validated['event']),
            'status' => 'pending',
            'is_test' => true,
            // A söprő ne vegye fel párhuzamosan (ugyanaz az ok, mint a dispatchernél).
            'next_attempt_at' => now()->addMinutes(2),
        ]);

        $audit->log('webhook.test_sent', $endpoint, context: ['event' => $validated['event'], 'delivery' => (string) $delivery->uuid]);

        DeliverWebhook::dispatch((int) $delivery->workspace_id, (string) $delivery->uuid);

        return back()->with('success', __('ventmail.plat.webhookTestQueued'));
    }

    /**
     * A teszt-payload — PONTOSAN a valódi payload alakja (`WebhookDispatcher::payload`),
     * `test: true` jelöléssel. A dokumentáció is ezt mutatja mintaként.
     *
     * @return array<string, mixed>
     */
    public static function samplePayload(string $event): array
    {
        $bounce = $event === 'bounced';

        return [
            'event' => $event,
            'timestamp' => now()->getTimestamp(),
            'test' => true,
            'message' => [
                'id' => (string) Str::uuid(),
                'recipient' => 'recipient@example.com',
                'channel' => 'transactional',
                'tags' => ['test'],
            ],
            'reason' => $bounce ? 'hard_bounce' : null,
            'variables' => ['order_id' => 'TEST-1001'],
            'delivery' => [
                'code' => $bounce ? 550 : ($event === 'delivered' ? 250 : null),
                'enhanced_code' => $bounce ? '5.1.1' : ($event === 'delivered' ? '2.0.0' : null),
                'classification' => $bounce ? 'hard' : null,
            ],
        ];
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

        app(AuditLogger::class)->log('webhook.replayed', $delivery->endpoint, context: ['delivery' => (string) $delivery->uuid]);

        return back()->with('success', __('ventmail.webhooks.replayed'));
    }
}
