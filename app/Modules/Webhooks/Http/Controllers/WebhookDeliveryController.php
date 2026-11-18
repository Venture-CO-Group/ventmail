<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A dead-letter sor ÚJRAJÁTSZÁSA (28. kritérium).
 *
 * A DLQ csak akkor ér valamit, ha van belőle kivezető út: az ügyfél megjavítja a végpontot,
 * és újraküldeti azt, ami nem jutott el. A sikeres replay `delivered`-re állítja a sort,
 * tehát a lista magától ürül — nem marad örökre növekvő szemétdomb.
 */
final class WebhookDeliveryController
{
    public function index(Request $request): JsonResponse
    {
        $deliveries = WebhookDelivery::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->limit(min((int) $request->integer('limit', 50), 200))
            ->get();

        return response()->json([
            'items' => $deliveries->map(fn (WebhookDelivery $delivery) => [
                'id' => $delivery->uuid,
                'event' => $delivery->event_type,
                'message_id' => $delivery->message_uuid,
                'status' => $delivery->status,
                'attempt' => $delivery->attempt,
                'response_code' => $delivery->response_code,
                'response_excerpt' => $delivery->response_excerpt,
                'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
                'dead_lettered_at' => $delivery->dead_lettered_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function replay(Request $request, string $uuid): JsonResponse
    {
        $delivery = WebhookDelivery::query()->where('uuid', $uuid)->first();

        if ($delivery === null) {
            return ApiError::badRequest('UNKNOWN_DELIVERY', 'Ismeretlen webhook-kézbesítés.');
        }

        if ($delivery->status === 'delivered') {
            return ApiError::badRequest('ALREADY_DELIVERED', 'Ez a kézbesítés már sikeres volt.');
        }

        // A replay a számlálót NEM nullázza: a trace-nek meg kell mutatnia, hányadik
        // kísérletnél jártunk — különben egy örökre hibázó végpont végtelen ciklusba
        // vihetné a sort.
        $delivery->forceFill(['status' => 'failed', 'next_attempt_at' => now()])->save();

        // Ugyanaz az audit-sor, mint a felületi replay-nél: a gépi út sem lehet nyomtalan.
        app(AuditLogger::class)->log('webhook.replayed', $delivery->endpoint, context: [
            'delivery' => (string) $delivery->uuid,
            'via' => 'api',
            // API-kulcsos hívásnál nincs felhasználó: a KULCS az aktor (uuid, nem a titok).
            'api_key' => $request->attributes->get('api_key')?->uuid,
        ]);

        DeliverWebhook::dispatch((int) $delivery->workspace_id, (string) $delivery->uuid);

        return response()->json(['id' => $delivery->uuid, 'status' => 'queued'], 202);
    }
}
