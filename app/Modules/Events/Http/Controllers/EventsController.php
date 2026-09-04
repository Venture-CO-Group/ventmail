<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Services\DomainNormalizer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;

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
 *
 * A kurzor viszont TITKOSÍTVA megy ki, és az eseményt a `uuid` azonosítja. A belső `id` egy
 * TENANT-FÜGGETLEN szekvencia: base64-ben kiadva bárki két hívásból (09:00 → 10:00)
 * megbecsülné a platform teljes esemény-volumenét. A rendezés maradhat a gyors `(occurred_at,
 * id)` páros — csak épp nem a hívón keresztül utazik nyíltan.
 */
final class EventsController
{
    private const MAX_LIMIT = 300;

    /** Az elfogadott eseménytípusok — a `/v1` szerződés zárt listája. */
    private const TYPES = [
        'accepted', 'rejected', 'delivered', 'deferred', 'bounced',
        'complained', 'unsubscribed', 'opened', 'clicked', 'stored',
    ];

    public function __invoke(Request $request, DomainNormalizer $normalizer): JsonResponse
    {
        // VALIDÁCIÓ ELŐSZÖR. Enélkül a `?since=tegnap` egy `InvalidFormatException`-ön át
        // nyers 500-at ad, a `?recipient[]=a` pedig „Array to string conversion"-t — a `/v1`
        // egységes hibaformátuma (golden rule 7) mindkét esetben megszűnne.
        $data = $request->query();

        // A `type` egy vagy több érték is lehet (`?type=delivered` és `?type[]=…`): a
        // szabályokat EGY alakra írjuk, különben a skalár változat kiesne az `in:` alól.
        $data['type'] = Arr::wrap($data['type'] ?? []);

        $validator = Validator::make($data, [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string'],
            'type' => ['array'],
            'type.*' => ['string', 'in:'.implode(',', self::TYPES)],
            'recipient' => ['sometimes', 'string', 'max:320'],
            'channel' => ['sometimes', 'string', 'in:transactional,marketing'],
            'message_id' => ['sometimes', 'string', 'max:64'],
            'tag' => ['sometimes', 'string', 'max:128'],
            'since' => ['sometimes', 'date'],
            'until' => ['sometimes', 'date'],
            'variable' => ['sometimes', 'array'],
            'variable.*' => ['string', 'max:256'],
        ]);

        if ($validator->fails()) {
            return ApiError::response(
                'VALIDATION_ERROR',
                'A lekérdezés paraméterei érvénytelenek.',
                400,
                $validator->errors()->toArray(),
            );
        }

        $limit = (int) $request->integer('limit', 100);

        $query = MessageEvent::query()->orderByDesc('occurred_at')->orderByDesc('id');

        $this->applyFilters($query, $request, $normalizer);

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
                'id' => $event->uuid,
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

    private function applyFilters(Builder $query, Request $request, DomainNormalizer $normalizer): void
    {
        $query
            ->when($request->filled('type'), fn (Builder $q) => $q->whereIn('type', Arr::wrap($request->input('type'))))
            // A CÍMET ugyanazzal a normalizálóval alakítjuk, amivel tároltuk: enélkül a
            // `user@példa.hu`-ra szűrve SOHA nincs találat (tárolva punycode-ban van).
            ->when($request->filled('recipient'), fn (Builder $q) => $q->where('recipient', $normalizer->email($request->string('recipient')->toString())))
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', $request->string('channel')->toString()))
            ->when($request->filled('message_id'), fn (Builder $q) => $q->where('message_uuid', $request->string('message_id')->toString()))
            ->when($request->filled('tag'), fn (Builder $q) => $q->whereJsonContains('tags', $request->string('tag')->toString()))
            ->when($request->filled('since'), fn (Builder $q) => $q->where('occurred_at', '>=', $request->date('since')))
            ->when($request->filled('until'), fn (Builder $q) => $q->where('occurred_at', '<=', $request->date('until')));

        // `v:*` változóra szűrés (Mailgun-paritás): `?variable[rendeles]=A-42`.
        foreach ((array) $request->input('variable', []) as $name => $value) {
            // A KULCS a kérésből jön, és JSON-útvonalként kerül a lekérdezésbe: engedélyezett
            // karakterkészletre szorítjuk. Enélkül a `variables->…` kifejezésbe idézőjel
            // vagy zárójel kerülhetne — a `v:*` nevek amúgy is egyszerű azonosítók.
            if (is_string($name) && is_scalar($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) === 1) {
                $query->where('variables->'.$name, (string) $value);
            }
        }
    }

    /** A kurzor ÁTLÁTSZATLAN: a belső sorszám nem a hívó dolga. */
    private function encodeCursor(MessageEvent $event): string
    {
        return Crypt::encryptString($event->occurred_at?->toIso8601String().'|'.$event->id);
    }

    /** @return array{0: string, 1: int}|null */
    private function decodeCursor(string $cursor): ?array
    {
        try {
            $decoded = Crypt::decryptString($cursor);
        } catch (DecryptException) {
            return null;
        }

        if (! str_contains($decoded, '|')) {
            return null;
        }

        [$occurredAt, $id] = explode('|', $decoded, 2);

        return ctype_digit($id) && strtotime($occurredAt) !== false ? [$occurredAt, (int) $id] : null;
    }
}
