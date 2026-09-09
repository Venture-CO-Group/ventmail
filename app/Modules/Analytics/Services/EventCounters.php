<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Events\Models\MessageEvent;

/**
 * A SZÁMLÁLÓK EGYETLEN FORRÁSA: a `message_events` (73. kritérium).
 *
 * ## Miért nincs saját számláló az analitikában
 *
 * Mert két számláló elkerülhetetlenül elcsúszik. Az egyik a küldésnél nő, a másik az
 * esemény beérkezésénél; az egyik újrapróbálásnál is, a másik nem; az egyiket egy
 * migráció újraszámolja, a másikat nem. Onnantól a tenant KÉT „delivered" számot lát
 * ugyanarra a kampányra a napló-oldalon és a marketing-riportban — és joggal nem hisz
 * egyikben sem.
 *
 * A `campaigns.sent_count` SZÁNDÉKOSAN nem forrás: az a KÜLDÉSI folyamat számlálója
 * (kötegenként nő, a kapu-elutasításokat `failed`-ként könyveli), a `delivered` viszont a
 * FOGADÓ visszajelzése. A kettő elvileg sem ugyanaz.
 *
 * ## EGY LEKÉRDEZÉS, feltételes aggregátumokkal
 *
 * Nyolc típusra nyolc `count()` nyolc körutazás lenne, a riport-oldalon soronként. A
 * `filter (where …)` a Postgres módja arra, hogy egy pásztázásból mind a nyolc szám
 * kijöjjön — és mellé az EGYEDI címzett-szám is (`count(distinct recipient)`), ami a
 * ráták nevezőjéhez kell.
 *
 * ## A TENANT-HATÁR a modellen van
 *
 * A `MessageEvent` tenant-kötött modell, tehát a global scope adja a `workspace_id`
 * szűrőt. Ez nem apróság: a tag a kampány uuid-ja, de a tagek a HÍVÓ szavai — egy hibás
 * integráció bármit beírhat a sajátjába, és egy elfelejtett `where` idegen tenant számát
 * adná a riportba.
 */
final class EventCounters
{
    /** @var list<string> */
    public const TYPES = [
        'accepted', 'delivered', 'deferred', 'bounced',
        'complained', 'unsubscribed', 'opened', 'clicked',
    ];

    /**
     * A SZÁMOK egy tag-halmazra.
     *
     * @param  list<string>  $tags  MINDEGYIKNEK szerepelnie kell az eseményen (ÉS-kapcsolat)
     * @return array<string, int>
     */
    public function forTags(array $tags): array
    {
        $query = MessageEvent::query();

        foreach ($tags as $tag) {
            // `whereJsonContains` → `tags @> '["…"]'`: a jsonb tömb TARTALMAZZA a taget. A
            // több hívás ÉS-kapcsolat, tehát a `variant:A` a kampány-tag MELLETT szűr.
            $query->whereJsonContains('tags', $tag);
        }

        $selects = [];

        foreach (self::TYPES as $type) {
            // A TÍPUS-LISTA konstans, nem kívülről jövő érték — a `DB::raw` itt biztonságos.
            $selects[] = "count(*) filter (where type = '{$type}') as {$type}";

            // AZ EGYEDI CÍMZETT csak ott számít, ahol a többszöri esemény VALÓS: a nyitást
            // egy Apple MPP-s postafiók tízszer kiváltja, a `delivered` viszont egyszer jön.
            if (in_array($type, ['opened', 'clicked'], true)) {
                $selects[] = "count(distinct recipient) filter (where type = '{$type}') as unique_{$type}";
            }
        }

        $row = $query->selectRaw(implode(', ', $selects))->first();

        $counts = [];

        foreach (self::TYPES as $type) {
            $counts[$type] = (int) ($row->{$type} ?? 0);
        }

        $counts['unique_opened'] = (int) ($row->unique_opened ?? 0);
        $counts['unique_clicked'] = (int) ($row->unique_clicked ?? 0);

        return $counts;
    }

    /**
     * A RÁTÁK a számokból — a nevezők a `Rates` docblockjában vannak kimondva.
     *
     * @param  array<string, int>  $counts
     * @return array<string, float>
     */
    public function rates(array $counts): array
    {
        $delivered = $counts['delivered'] ?? 0;
        $accepted = $counts['accepted'] ?? 0;

        return [
            'open_rate' => Rates::percent($counts['unique_opened'] ?? 0, $delivered),
            'click_rate' => Rates::percent($counts['unique_clicked'] ?? 0, $delivered),
            'click_to_open_rate' => Rates::percent($counts['unique_clicked'] ?? 0, $counts['unique_opened'] ?? 0),
            'bounce_rate' => Rates::percent($counts['bounced'] ?? 0, $accepted),
            'complaint_rate' => Rates::percent($counts['complained'] ?? 0, $delivered),
            'unsubscribe_rate' => Rates::percent($counts['unsubscribed'] ?? 0, $delivered),
        ];
    }

    /**
     * A NAPI IDŐSOR egy tag-halmazra — a riport grafikonjához.
     *
     * A `message_event_daily` folytonos aggregátumot SZÁNDÉKOSAN nem használjuk itt: az a
     * (nap, csatorna, típus, TAG) grain-en áll, tehát egy kampány + variáns szűrés (két tag
     * EGYÜTT) nem kérdezhető belőle — az aggregátum a taget szétszórja, nem kombinálja. A
     * napi bontás ezért a nyers eseményekből jön, ugyanabból a táblából, amiből az
     * aggregátum is épül: a szám így nem tud elcsúszni.
     *
     * @param  list<string>  $tags
     * @return list<array{day: string, delivered: int, unique_opened: int, unique_clicked: int}>
     */
    public function dailyForTags(array $tags): array
    {
        $query = MessageEvent::query();

        foreach ($tags as $tag) {
            $query->whereJsonContains('tags', $tag);
        }

        $rows = $query
            ->selectRaw("date_trunc('day', occurred_at) as day")
            ->selectRaw("count(*) filter (where type = 'delivered') as delivered")
            ->selectRaw("count(distinct recipient) filter (where type = 'opened') as unique_opened")
            ->selectRaw("count(distinct recipient) filter (where type = 'clicked') as unique_clicked")
            ->groupByRaw("date_trunc('day', occurred_at)")
            ->orderByRaw("date_trunc('day', occurred_at)")
            ->get();

        return $rows->map(static fn (object $row): array => [
            // A NAP dátum-sztringként: a grafikon tengelye ezt kapja, és így nem a hívó
            // időzónája dönti el, melyik naphoz tartozik egy esemény.
            'day' => substr((string) $row->day, 0, 10),
            'delivered' => (int) $row->delivered,
            'unique_opened' => (int) $row->unique_opened,
            'unique_clicked' => (int) $row->unique_clicked,
        ])->all();
    }
}
