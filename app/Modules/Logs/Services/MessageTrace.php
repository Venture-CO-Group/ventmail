<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Logs\Models\SmtpTransaction;
use App\Modules\Sending\Models\DeliveryAttempt;
use App\Modules\Sending\Models\Message;
use Illuminate\Support\Str;

/**
 * ÜZENET-TRACE (23. kritérium) — a „miért nem érkezett meg?" válasza.
 *
 * A lánc EGYBEN: sorbaállítás → a gate MINDEN kapujának eredménye → a kiválasztott IP és
 * pool → az SMTP-párbeszéd sorai a fogadó válaszkódjával → kézbesítés vagy bounce okkal →
 * nyitás/klikk.
 *
 * Miért egy külön szolgáltatás: ez az a képernyő, amit a support használ, és amit a
 * fejlesztő megnyit, amikor az ügyfél azt írja, „nem jött meg". Ha a darabok külön
 * lekérdezésekben élnének, mindenki mást rakna össze belőlük.
 */
final class MessageTrace
{
    /**
     * Egy trace-be ennyi sor fér.
     *
     * Korlát nélkül egy 1000 címzettes batch trace-e ezer SMTP-párbeszédet, ezer kísérletet
     * és több ezer eseményt töltene EGYETLEN Inertia-payloadba — a „miért nem érkezett meg?"
     * képernyő pont akkor állna meg, amikor a legnagyobb szükség lenne rá.
     */
    private const MAX_ROWS = 200;

    /** @return array<string, mixed>|null */
    public function for(string $uuid): ?array
    {
        // A `uuid` oszlop típusos: egy elgépelt azonosító különben adatbázis-hibát
        // (és 500-at) adna a felületnek, nem „nincs ilyen üzenet"-et.
        if (! Str::isUuid($uuid)) {
            return null;
        }

        $message = Message::query()->with(['ipAddress', 'domain'])->where('uuid', $uuid)->first();

        if ($message === null) {
            return null;
        }

        return [
            'message' => [
                'uuid' => $message->uuid,
                'status' => $message->status,
                'reason' => $message->reason,
                'channel' => $message->channel,
                'from' => $message->from_email,
                'recipients' => $message->recipients,
                'subject' => $message->subject,
                'tags' => $message->tags,
                'attempts' => (int) $message->attempts,
                'queued_at' => $message->queued_at?->toIso8601String(),
                'sent_at' => $message->sent_at?->toIso8601String(),
                'next_attempt_at' => $message->next_attempt_at?->toIso8601String(),
                // Az aláíratlanul kiment levél NYOMA (A2): DMARC-bukást okoz, tehát a
                // trace-ben látnia kell a supportnak.
                'dkim_unsigned' => $message->options['dkim_unsigned'] ?? null,
            ],
            // A gate MINDEN kapujának eredménye — nem csak az elutasítás oka. A „miért ment
            // ki" kérdésre ugyanúgy válasz kell, mint a „miért nem"-re.
            'gate' => [
                'decided_at' => $message->gate_decided_at?->toIso8601String(),
                'decision' => $message->reason === null ? 'allowed' : 'rejected',
                'gates' => $message->options['gate_trace'] ?? null,
            ],
            'routing' => [
                'ip_pool_id' => $message->ip_pool_id,
                'ip_address' => $message->ipAddress?->address,
                'domain' => $message->domain?->name,
            ],
            'smtp' => SmtpTransaction::query()
                ->where('message_uuid', $uuid)
                ->orderBy('id')
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn (SmtpTransaction $transaction) => [
                    'recipient' => $transaction->recipient,
                    'remote_host' => $transaction->remote_host,
                    'code' => $transaction->response_code,
                    'enhanced_code' => $transaction->enhanced_code,
                    'text' => $transaction->response_text,
                    'lines' => $transaction->lines,
                    'started_at' => $transaction->started_at?->toIso8601String(),
                ])->all(),
            'attempts' => DeliveryAttempt::query()
                ->where('message_uuid', $uuid)
                ->orderBy('id')
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn (DeliveryAttempt $attempt) => [
                    'attempt' => $attempt->attempt,
                    'recipient' => $attempt->recipient,
                    'code' => $attempt->code,
                    'classification' => $attempt->classification,
                    'response' => $attempt->response,
                    'at' => $attempt->created_at?->toIso8601String(),
                ])->all(),
            'events' => MessageEvent::query()
                ->where('message_uuid', $uuid)
                ->orderBy('occurred_at')
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn (MessageEvent $event) => [
                    'type' => $event->type,
                    'recipient' => $event->recipient,
                    'reason' => $event->reason,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                    // A TÖRZS sosem kerül a naplóba — csak a lenyomata (A1).
                    'body_sha256' => $event->payload['body_sha256'] ?? null,
                ])->all(),
        ];
    }
}
