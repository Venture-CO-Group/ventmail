<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\Events\Models\MessageEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /v1/events` — a Mailgun events API paritása (27. kritérium).
 *
 * A LAPOZÁS KURZOROS, és ez nem stílus kérdése: az eseménytábla FOLYAMATOSAN nő, tehát
 * offset-lapozásnál a 2. oldal lekérésekor a sorok elcsúsznak — a hívó ugyanazt az eseményt
 * kétszer kapja meg, vagy sosem látja. Egy számlázási vagy suppression-szinkron ebből
 * csendben rossz adatot építene.
 *
 * A kurzor az `(occurred_at, id)` PÁROS: az `occurred_at` önmagában nem egyedi (egy batch
 * ezer eseménye ugyanabban a másodpercben keletkezik), tehát azzal lapozva sorok maradnának
 * ki. A hypertable elsődleges kulcsa pontosan ez a páros.
 */
final class EventsController
{
    private const MAX_LIMIT = 300;

    public function __invoke(Request $request): JsonResponse
    {
        $limit = min((int) $request->integer('limit', 100), self::MAX_LIMIT);

        if ($limit < 1) {
            return ApiError::badRequest('VALIDATION_ERROR', 'A `limit` legalább 1 legyen.');
        }

        $query = MessageEvent::query()->orderByDesc('occurred_at')->orderByDesc('id');

        $this->applyFilters($query, $request);

        if (($cursor = $request->string('cursor')->toString()) !== '') {
            $decoded = $this->decodeCursor($cursor);

            if ($decoded === null) {
                return ApiError::badRequest('VALIDATION_ERROR', 'Érvénytelen kurzor.');
            }

            [$occurredAt, $id] = $decoded;

            // Szigorúan a kurzor MÖGÖTTI sorok — az `(occurred_at, id)` páros szerint.
            $query->where(fn (Builder $inner) => $inner
                ->where('occurred_at', '<', $occurredAt)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('occurred_at', '=', $occurredAt)
                    ->where('id', '<', $id)));
        }

        // Eggyel többet kérünk, mint amennyit adunk: így tudjuk, van-e még oldal, anélkül
        // hogy külön `count(*)`-ot futtatnánk egy folyamatosan növő táblán.
        $events = $query->limit($limit + 1)->get();

        $hasMore = $events->count() > $limit;
        $items = $events->take($limit);

        return response()->json([
            'items' => $items->map(fn (MessageEvent $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'message_id' => $event->message_uuid,
                'recipient' => $event->recipient,
                'channel' => $event->channel,
                'reason' => $event->reason,
                'tags' => $event->tags,
                // A `v:*` változók VISSZAJÖNNEK (Mailgun-paritás): az integrációk ebből
                // azonosítják a saját rekordjaikat.
                'variables' => $event->variables,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->values(),
            'paging' => [
                'next' => $hasMore && $items->isNotEmpty()
                    ? $this->encodeCursor($items->last())
                    : null,
            ],
        ]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $query
            ->when($request->filled('type'), fn (Builder $q) => $q->whereIn('type', (array) $request->input('type')))
            ->when($request->filled('recipient'), fn (Builder $q) => $q->where('recipient', $request->string('recipient')->toString()))
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', $request->string('channel')->toString()))
            ->when($request->filled('message_id'), fn (Builder $q) => $q->where('message_uuid', $request->string('message_id')->toString()))
            ->when($request->filled('tag'), fn (Builder $q) => $q->whereJsonContains('tags', $request->string('tag')->toString()))
            ->when($request->filled('since'), fn (Builder $q) => $q->where('occurred_at', '>=', $request->date('since')))
            ->when($request->filled('until'), fn (Builder $q) => $q->where('occurred_at', '<=', $request->date('until')));

        // `v:*` változóra szűrés (Mailgun-paritás): `?variable[rendeles]=A-42`.
        foreach ((array) $request->input('variable', []) as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $query->where('variables->'.$name, (string) $value);
            }
        }
    }

    private function encodeCursor(MessageEvent $event): string
    {
        return base64_encode($event->occurred_at?->toIso8601String().'|'.$event->id);
    }

    /** @return array{0: string, 1: int}|null */
    private function decodeCursor(string $cursor): ?array
    {
        $decoded = base64_decode($cursor, true);

        if ($decoded === false || ! str_contains($decoded, '|')) {
            return null;
        }

        [$occurredAt, $id] = explode('|', $decoded, 2);

        return ctype_digit($id) && strtotime($occurredAt) !== false ? [$occurredAt, (int) $id] : null;
    }
}
