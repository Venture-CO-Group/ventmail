<?php

declare(strict_types=1);

namespace App\Modules\Sending\Data;

/**
 * A küldési sorok — és a KÖZTÜK lévő prioritás (18. kritérium).
 *
 * Golden rule 4: a `campaign` sor SOHA nem előzheti meg a `transactional`-t. Egy százezres
 * kampány mellett a jelszó-visszaállító levél különben órákat várna — ez a leggyakoribb
 * ESP-panasz, nálunk pedig szabály.
 */
enum SendQueue: string
{
    case Transactional = 'transactional';
    case Journey = 'journey';
    case Campaign = 'campaign';

    public static function forChannel(string $channel): self
    {
        return match ($channel) {
            'transactional' => self::Transactional,
            'journey' => self::Journey,
            // Az ISMERETLEN csatorna a LEGALACSONYABB prioritást kapja, nem a legmagasabbat:
            // egy jövőbeli csatorna ne tudja véletlenül megelőzni a tranzakciós levelet.
            default => self::Campaign,
        };
    }

    /** @return list<string> a worker ebben a sorrendben veszi ki a munkát */
    public static function priorityOrder(): array
    {
        return array_map(fn (self $queue) => $queue->value, self::cases());
    }
}
