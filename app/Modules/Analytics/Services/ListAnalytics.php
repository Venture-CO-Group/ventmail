<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Audience\Models\ContactList;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * LISTA-RIPORT: NÖVEKEDÉS és jelenlegi állapot (72. kritérium).
 *
 * ## Miért a NÖVEKEDÉS a lista fő mutatója, és nem a nyitási arány
 *
 * Egy lista nem küld levelet — a kampány küld. A listához tartozó kérdés az, hogy NŐ-e:
 * hányan iratkoznak fel és hányan le, naponta. A nettó változás az egyetlen szám, amiből a
 * tenant tudja, hogy a közönségépítése működik-e.
 *
 * Az ENGAGEMENT (nyitás, klikk) ezért itt SZÁNDÉKOSAN nincs: az a KAMPÁNYHOZ tartozik. Egy
 * „a lista nyitási aránya 24%" szám félrevezető lenne — a listát több kampány célozza, más
 * tárgysorral és más időben; az átlaguk nem a listáról mond valamit.
 *
 * ## A TAGSÁG-SOR IDŐBÉLYEGEI a forrás
 *
 * A `contact_list_memberships` `subscribed_at`/`unsubscribed_at` mezői. Ez a KETTŐ mondja
 * meg a mikor-t, és a `status` a jelenlegi állapotot. Külön esemény-táblát nem építünk:
 * ugyanaz a hiba lenne, mint két számláló — a tagság-sor és az esemény-sor elcsúszna.
 *
 * A leiratkozott tagság-sor MEGMARAD (a tény bizonyíték, és a B1 ezt kimondja): a
 * növekedési görbe csak így számolható visszamenőleg.
 */
final class ListAnalytics
{
    public function __construct(private readonly WorkspaceContext $context) {}

    /**
     * A JELENLEGI állapot.
     *
     * @return array{list: string, list_uuid: string, subscribed: int, unsubscribed: int, pending: int, total: int}
     */
    public function summary(ContactList $list): array
    {
        /*
         | A LEKÉRDEZÉS A RELÁCIÓN megy, nem nyers query builderen.
         |
         | A `contact_list_memberships` PIVOT tábla: nincs rajta modell, tehát global scope
         | sem. A `$list->contacts()` viszont a `Contact` MODELLT kérdezi a pivot-joinnal —
         | és így a `contacts.workspace_id` szűrőt a global scope adja, nem egy kézzel
         | odaírt `where`, amit el lehet felejteni.
         |
         | A `withTrashed()` KELL: a GDPR-törlés a kontaktot soft-delete-eli, a tagság-sor
         | viszont megmarad. Nélküle a lista MÉRETE visszamenőleg csökkenne minden törlési
         | kérésnél — a tenant azt látná, hogy a közönsége magától fogy, és a fél évvel
         | korábbi növekedési görbe ma más számot mutatna, mint tegnap.
         */
        $byStatus = $list->contacts()
            ->withTrashed()
            ->reorder()
            ->selectRaw('contact_list_memberships.status as status, count(*) as total')
            ->groupBy('contact_list_memberships.status')
            ->pluck('total', 'status');

        $subscribed = (int) ($byStatus['subscribed'] ?? 0);
        $unsubscribed = (int) ($byStatus['unsubscribed'] ?? 0);
        $pending = (int) ($byStatus['pending'] ?? 0);

        return [
            'list' => (string) $list->name,
            'list_uuid' => (string) $list->uuid,
            'subscribed' => $subscribed,
            'unsubscribed' => $unsubscribed,
            'pending' => $pending,
            'total' => $subscribed + $unsubscribed + $pending,
        ];
    }

    /**
     * A NÖVEKEDÉSI GÖRBE napi bontásban.
     *
     * ## Miért KÉT lekérdezés uniója, és nem egy
     *
     * Egy tagság-sor KÉT naphoz tartozik: a feliratkozás napjához és — ha leiratkozott — a
     * leiratkozás napjához. Egy sima `group by date(subscribed_at)` a leiratkozásokat
     * elveszítené, egy `group by date(unsubscribed_at)` pedig a feliratkozásokat. A kettő
     * uniója az egyetlen alak, amiben mindkét irány a SAJÁT napján jelenik meg.
     *
     * A HIÁNYZÓ NAPOK is szerepelnek nullával: egy grafikon, ami kihagyja az üres napokat,
     * összenyomja az időtengelyt, és a trend meredekebbnek látszik a valóságnál.
     *
     * @return list<array{day: string, subscribed: int, unsubscribed: int, net: int}>
     */
    public function growth(ContactList $list, Carbon $from, Carbon $to): array
    {
        $this->workspaceId();

        $subscribes = $this->byDayCounts($list, 'subscribed_at', $from, $to);
        $unsubscribes = $this->byDayCounts($list, 'unsubscribed_at', $from, $to);

        $up = $subscribes;
        $down = $unsubscribes;

        $days = [];

        // A HIÁNYZÓ NAPOK is szerepelnek — lásd a docblockot.
        for ($day = $from->copy()->startOfDay(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $key = $day->toDateString();

            $subscribed = (int) ($up[$key] ?? 0);
            $unsubscribed = (int) ($down[$key] ?? 0);

            $days[] = [
                'day' => $key,
                'subscribed' => $subscribed,
                'unsubscribed' => $unsubscribed,
                'net' => $subscribed - $unsubscribed,
            ];
        }

        return $days;
    }

    /**
     * EGY IRÁNY napi darabszámai — a reláción, a global scope-pal.
     *
     * A `$column` a KÉT FIX pivot-oszlop egyike (`subscribed_at` / `unsubscribed_at`), nem
     * kívülről jövő érték — így a `selectRaw` biztonságos.
     *
     * A NAP KULCSA `YYYY-MM-DD`: a Postgres `timestamptz`-t ad vissza, aminek a
     * sztring-alakja driver- és időzóna-függő.
     *
     * @return array<string, int>
     */
    private function byDayCounts(ContactList $list, string $column, Carbon $from, Carbon $to): array
    {
        $qualified = 'contact_list_memberships.'.$column;

        $rows = $list->contacts()
            ->withTrashed()
            ->reorder()
            ->whereNotNull($qualified)
            ->whereBetween($qualified, [$from, $to])
            ->selectRaw("date_trunc('day', {$qualified}) as day, count(*) as total")
            ->groupByRaw("date_trunc('day', {$qualified})")
            ->pluck('total', 'day');

        $byDay = [];

        foreach ($rows as $day => $total) {
            $byDay[substr((string) $day, 0, 10)] = (int) $total;
        }

        return $byDay;
    }

    /**
     * A TENANT-SZŰRŐ itt KÉZI, és ez tudatos.
     *
     * A `contact_list_memberships` PIVOT tábla: nincs rajta Eloquent-modell, tehát global
     * scope sem lehet. Ugyanaz a helyzet, mint a `message_event_daily` nézetnél, és ugyanaz
     * a válasz: a szűrő EBBE a szolgáltatásba van bezárva, és a kontextus hiánya KIVÉTEL,
     * nem „minden tenant". Egy elfelejtett `where` itt egy másik ügyfél közönségének a
     * méretét adná a riportba.
     */
    private function workspaceId(): int
    {
        if (! $this->context->hasWorkspace()) {
            throw new RuntimeException('A lista-riport workspace-kontextus nélkül nem kérdezhető le.');
        }

        return $this->context->workspaceId();
    }
}
