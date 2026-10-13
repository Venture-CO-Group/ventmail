<?php

declare(strict_types=1);

namespace App\Modules\Journey\Services;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Events\Models\MessageEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * A FELTÉTELEK kiértékelése — a motor ÉS a teszt-futtató közös, CSAK OLVASÓ kódja.
 *
 * Négy fajta: szegmens-tagság (a B1 motorján), esemény bekövetkezte (nyitás, klikk), címke,
 * a futás kontextusa (a trigger payloadja) és — v1.1 — a kontakt egy mezője. Mind
 * DETERMINISZTIKUS: ugyanaz az adat ugyanazt az eredményt adja. A teszt-futtatás ezért
 * ugyanazt az ágat mutatja, amit a valódi futás választana.
 *
 * AZ ISMERETLEN FELTÉTEL HAMIS (fail-closed): egy elgépelt típus az `else` ágra vezet, nem a
 * levélküldésre.
 */
final class JourneyConditions
{
    public function __construct(private readonly SegmentEngine $segments) {}

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    public function evaluate(array $config, string $email, ?Contact $contact, array $context = []): bool
    {
        $type = (string) ($config['type'] ?? '');

        return match ($type) {
            'in_segment' => $this->inSegment($email, (string) ($config['segment'] ?? '')),
            'opened_email', 'clicked_email' => $this->hadEvent(
                $email,
                $type === 'opened_email' ? 'opened' : 'clicked',
                now()->subHours(max(1, (int) ($config['within_hours'] ?? 168))),
            ),
            'has_tag' => $this->hasTag($contact, (string) ($config['tag'] ?? '')),
            'context_equals' => (string) data_get($context, (string) ($config['key'] ?? ''), '')
                === (string) ($config['value'] ?? ''),
            'field_equals' => $this->fieldValue($contact, (string) ($config['key'] ?? ''))
                === trim((string) ($config['value'] ?? '')),
            default => false,
        };
    }

    public function inSegment(string $email, string $segmentUuid): bool
    {
        $segment = self::uuidOrNull($segmentUuid) === null
            ? null
            : Segment::query()->where('uuid', $segmentUuid)->first();

        if ($segment === null) {
            return false;
        }

        return $this->segments->query((array) $segment->definition)
            ->where('contacts.email', $email)
            ->exists();
    }

    public function hadEvent(string $email, string $type, CarbonInterface $since): bool
    {
        return MessageEvent::query()
            ->where('type', $type)
            // KIS/NAGYBETŰ-FÜGGETLEN: a `contacts.email` a `listKey()` alakja, a
            // `message_events.recipient` viszont a helyi részt érintetlenül hagyja.
            ->whereRaw('lower(recipient) = ?', [$email])
            ->where('occurred_at', '>=', $since)
            ->exists();
    }

    public function hasTag(?Contact $contact, string $tagUuid): bool
    {
        if ($contact === null || self::uuidOrNull($tagUuid) === null) {
            return false;
        }

        return $contact->tags()->where('contact_tags.uuid', $tagUuid)->exists();
    }

    /** A kontakt egy mezőjének értéke — a beépítettek oszlopból, az egyéniek az `attributes`-ből. */
    public function fieldValue(?Contact $contact, string $key): string
    {
        if ($contact === null || $key === '') {
            return '';
        }

        if (in_array($key, ['first_name', 'last_name'], true)) {
            return trim((string) $contact->getAttribute($key));
        }

        $attributes = (array) $contact->getAttribute('attributes');

        return trim((string) (is_scalar($attributes[$key] ?? null) ? $attributes[$key] : ''));
    }

    /**
     * A TÍPUSOS UUID-OSZLOPOK EGYETLEN KAPUJA: a Postgres `uuid` oszlopára nem-uuid sztringgel
     * lekérdezni `22P02`-t ad, nem üres találatot.
     */
    public static function uuidOrNull(string $value): ?string
    {
        return Str::isUuid(trim($value)) ? trim($value) : null;
    }
}
