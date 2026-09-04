<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Webhook-VÉGPONTOK kezelése (28. kritérium: „eseménytípusonként konfigurálható endpoint").
 *
 * Enélkül a teljes webhook-lánc halott kód lenne: a `webhook_endpoints` tábla élesben üres
 * maradna, mert semmi nem tudna sort írni bele.
 *
 * A TITOK EGYSZER látszik — a létrehozás válaszában. Utána már csak az aláírásunkban él;
 * visszaolvasni nem lehet, mert a tárolt alak titkosított, és nincs olyan út, ami kiadná.
 * Aki elvesztette, az újat kér (`rotate`), és a régi ugyanabban a pillanatban érvénytelen.
 */
final class WebhookEndpointController
{
    /** A `/v1/events` zárt típuslistája — ugyanaz a szerződés. */
    private const TYPES = [
        'accepted', 'rejected', 'delivered', 'deferred', 'bounced',
        'complained', 'unsubscribed', 'opened', 'clicked', 'stored',
    ];

    public function index(): JsonResponse
    {
        return response()->json([
            'items' => WebhookEndpoint::query()->orderBy('id')->get()
                ->map(fn (WebhookEndpoint $endpoint) => $this->present($endpoint))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:2048'],
            // ÜRES lista = minden esemény. Az explicit lista szűkít.
            'events' => ['sometimes', 'array'],
            'events.*' => ['string', 'in:'.implode(',', self::TYPES)],
        ]);

        if ($validator->fails()) {
            return ApiError::response('VALIDATION_ERROR', 'A végpont adatai érvénytelenek.', 400, $validator->errors()->toArray());
        }

        try {
            $issued = WebhookEndpoint::issue(
                (string) $request->input('name'),
                (string) $request->input('url'),
                array_values((array) $request->input('events', [])),
            );
        } catch (InvalidArgumentException $e) {
            // SSRF-elutasítás: a hibát OTT jelezzük, ahol az ügyfél beírja a címet, nem a
            // hatodik sikertelen kézbesítésnél.
            return ApiError::badRequest('INVALID_WEBHOOK_URL', $e->getMessage());
        } catch (RuntimeException $e) {
            return ApiError::badRequest('UNRESOLVABLE_WEBHOOK_URL', $e->getMessage());
        }

        return response()->json([
            ...$this->present($issued['endpoint']),
            // EGYSZER, és soha többé.
            'secret' => $issued['secret'],
        ], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $endpoint = WebhookEndpoint::query()->where('uuid', $uuid)->first();

        if ($endpoint === null) {
            return ApiError::response('UNKNOWN_ENDPOINT', 'Ismeretlen webhook-végpont.', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:120'],
            'url' => ['sometimes', 'string', 'max:2048'],
            'events' => ['sometimes', 'array'],
            'events.*' => ['string', 'in:'.implode(',', self::TYPES)],
            'active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ApiError::response('VALIDATION_ERROR', 'A végpont adatai érvénytelenek.', 400, $validator->errors()->toArray());
        }

        try {
            $endpoint->fill($validator->validated())->save();
        } catch (InvalidArgumentException $e) {
            return ApiError::badRequest('INVALID_WEBHOOK_URL', $e->getMessage());
        } catch (RuntimeException $e) {
            return ApiError::badRequest('UNRESOLVABLE_WEBHOOK_URL', $e->getMessage());
        }

        return response()->json($this->present($endpoint->fresh()));
    }

    /**
     * Titok-csere.
     *
     * A régi titok AZONNAL érvénytelen — nincs átfedési ablak, és ez szándékos: a rotációt
     * jellemzően szivárgás váltja ki, olyankor pedig a „még pár percig érvényes" a támadónak
     * kedvez. Az ügyfél a csere pillanatában frissíti a végpontját.
     */
    public function rotate(string $uuid): JsonResponse
    {
        $endpoint = WebhookEndpoint::query()->where('uuid', $uuid)->first();

        if ($endpoint === null) {
            return ApiError::response('UNKNOWN_ENDPOINT', 'Ismeretlen webhook-végpont.', 404);
        }

        $secret = 'whsec_'.Str::random(40);

        $endpoint->secret = $secret;
        $endpoint->save();

        return response()->json([...$this->present($endpoint), 'secret' => $secret]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $endpoint = WebhookEndpoint::query()->where('uuid', $uuid)->first();

        if ($endpoint === null) {
            return ApiError::response('UNKNOWN_ENDPOINT', 'Ismeretlen webhook-végpont.', 404);
        }

        $endpoint->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->uuid,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'active' => $endpoint->active,
            'last_success_at' => $endpoint->last_success_at?->toIso8601String(),
            'last_failure_at' => $endpoint->last_failure_at?->toIso8601String(),
        ];
    }
}
