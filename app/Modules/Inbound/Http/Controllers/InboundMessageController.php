<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Models\InboundAttachment;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Inbound\Services\InboundPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A TÁROLT bejövő levelek lekérése (33. kritérium).
 *
 * A `store()` akció csak akkor ér valamit, ha van hozzá kivezető út: az ügyfél API-n éri el,
 * amit eltettünk. A retenció lejártakor a sor eltűnik — a 404 tehát nem hiba, hanem a
 * megígért adatminimalizálás.
 */
final class InboundMessageController
{
    public function __construct(private readonly InboundPayload $payload) {}

    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->integer('limit', 50), 1), 200);

        $messages = InboundMessage::query()
            ->when($request->filled('recipient'), fn ($query) => $query->where('recipient', $request->string('recipient')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'items' => $messages->map(fn (InboundMessage $message) => [
                'id' => $message->uuid,
                'sender' => $message->sender,
                'recipient' => $message->recipient,
                'subject' => $message->subject,
                'status' => $message->status,
                'size' => $message->size,
                'received_at' => $message->received_at?->toIso8601String(),
                'expires_at' => $message->expires_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $message = InboundMessage::query()->where('uuid', $uuid)->with('attachments')->first();

        if ($message === null) {
            return ApiError::response('UNKNOWN_MESSAGE', 'Ismeretlen bejövő üzenet.', 404);
        }

        return response()->json($this->payload->for($message));
    }

    /**
     * A NYERS MIME.
     *
     * Külön végpont, mert nagy és ritkán kell: a payloadba beletenni minden lekérésnél
     * megabájtokat mozgatna feleslegesen.
     */
    public function raw(string $uuid, RawMailStore $store): mixed
    {
        $message = InboundMessage::query()->where('uuid', $uuid)->with('attachments')->first();

        if ($message === null) {
            return ApiError::response('UNKNOWN_MESSAGE', 'Ismeretlen bejövő üzenet.', 404);
        }

        // A NYERS MIME a csatolmányokat is TARTALMAZZA, base64-ben. A karantén-logikát
        // (`downloadable()`) ez a végpont megkerülné: egy fertőzött melléklet, amit a
        // csatolmány-végponton 409-cel tiltunk, itt szépen kijönne a levél törzsében.
        $blocked = $message->attachments->first(fn ($attachment) => $attachment->scan_result === 'infected');

        if ($blocked !== null) {
            return ApiError::response(
                'RAW_CONTAINS_INFECTED_ATTACHMENT',
                'A nyers levél fertőzött csatolmányt tartalmaz, ezért nem adható ki.',
                409,
            );
        }

        $raw = $store->get((string) $message->storage_key);

        if ($raw === null) {
            return ApiError::response('RAW_UNAVAILABLE', 'A nyers levél már nem érhető el (retenció).', 404);
        }

        return response($raw, 200, [
            'Content-Type' => 'message/rfc822',
            // Idegen tartalom: a böngésző ne találgassa a típust, és ne rendereljen.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="'.$message->uuid.'.eml"',
        ]);
    }

    /**
     * Csatolmány-letöltés — LEJÁRÓ, aláírt URL-lel.
     *
     * A fertőzött csatolmányra NEM adunk linket. Nem a mi dolgunk kártevőt terjeszteni, még
     * kérésre sem: az ügyfél a `scan` mezőből tudja, mi történt.
     */
    public function attachment(string $uuid, string $attachmentUuid, RawMailStore $store): JsonResponse
    {
        $attachment = InboundAttachment::query()
            ->where('uuid', $attachmentUuid)
            ->whereHas('message', fn ($query) => $query->where('uuid', $uuid))
            ->first();

        if ($attachment === null) {
            return ApiError::response('UNKNOWN_ATTACHMENT', 'Ismeretlen csatolmány.', 404);
        }

        if (! $attachment->downloadable()) {
            return ApiError::response(
                'ATTACHMENT_NOT_DOWNLOADABLE',
                'A csatolmány nem tölthető le (szkennelési eredmény: '.$attachment->scan_result.').',
                409,
            );
        }

        return response()->json([
            'name' => $attachment->filename,
            'content_type' => $attachment->content_type,
            'size' => $attachment->size,
            'url' => $store->temporaryUrl((string) $attachment->storage_key, (int) config('inbound.download_url_seconds')),
            'expires_in' => (int) config('inbound.download_url_seconds'),
        ]);
    }
}
