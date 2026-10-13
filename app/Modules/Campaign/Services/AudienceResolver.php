<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Campaign\Models\Campaign;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A CÉLZÁS FELOLDÁSA: lista ∪ szegmens MÍNUSZ kizárás (63. kritérium).
 *
 * ## A halmaz-műveletek sorrendje nem mindegy
 *
 * Először UNIÓ (a célzott listák és szegmensek együtt), utána KIVONÁS (a kizártak). A másik
 * sorrend — kizárás, majd unió — azt jelentené, hogy egy kizárt cím visszakerül, ha egy
 * másik célzott listán is szerepel. A kizárás SZÁNDÉKA az, hogy az adott ember NE kapja meg;
 * ezt egy unió nem írhatja felül.
 *
 * ## A DEDUPLIKÁCIÓ nem opcionális
 *
 * A lista ∪ szegmens ugyanazt a címet több forrásból is adhatja. Deduplikáció nélkül a
 * címzett kétszer (háromszor) kapná meg ugyanazt a kampányt — ez a legláthatóbb hiba, amit
 * egy hírlevélküldő el tud követni, és azonnal spam-panasszá válik. A kulcs a NORMALIZÁLT
 * cím, nem a kontakt id-je: két kontakt-sor ugyanarra a címre (import + űrlap) egyetlen
 * embert jelent.
 *
 * ## A HALMAZ AZ ADATBÁZISBAN ÁLL ÖSSZE, NEM A MEMÓRIÁBAN
 *
 * Ez korábban másképp volt, és a mérés kiadta: a `resolve()` a TELJES közönséget Eloquent
 * `Contact` modellként gyűjtötte, a `estimate()` pedig ugyanezt még egyszer lefuttatta.
 * 100 000 kontaktra +193 MB csúcs (1,98 KB/címzett), a konténer valódi
 * `memory_limit=128M`-jével pedig `Allowed memory size … exhausted`.
 *
 * És a memória-fatal NEM `Throwable`: a `DispatchScheduledCampaignsCommand` `catch`-e nem
 * fogja meg, tehát EGYETLEN nagy kampány megölte a teljes ütemező-futást, és a
 * workspace-ciklusban utána következő MINDEN kampány kimaradt — percenként újra. Pont az,
 * amit az a parancs a docblockjában megígér, hogy nem történhet meg.
 *
 * Ezért a célzás egyetlen SQL-lekérdezés (`UNION ALL` + `GROUP BY` + `NOT IN` a kizártakra),
 * és a hívó vagy SZÁMOL rajta (`estimate()`), vagy KÖTEGENKÉNT olvassa (`each()`). Modell
 * nem materializálódik, és a becslés meg a küldés UGYANARRA a lekérdezésre épül — így a
 * felületen mutatott szám és a kiküldött levelek száma nem tud eltérni.
 *
 * ## A DEDUPLIKÁCIÓ ÉS A KONTAKT-ID
 *
 * A `GROUP BY email` + `MIN(id)` azt jelenti, hogy két kontakt-sor ugyanarra a címre
 * (import + űrlap) EGY címzett, és mindig UGYANAZ az id kerül a riportba. Ez a
 * determinizmus feltétele: az A/B variáns-hozzárendelés a rendezett halmaz pozíciójából jön.
 */
final class AudienceResolver
{
    public function __construct(private readonly SegmentEngine $segments) {}

    /**
     * A CÉLZOTT címek KÖTEGENKÉNT — a küldés ezt használja.
     *
     * A rendezés a CÍMRE megy, mert a determinizmust az adja: ugyanaz a célzás ugyanabban a
     * sorrendben adja a címzetteket, tehát a variáns-hozzárendelés megismételhető. A
     * lapozás kulcsa is a cím (nem `OFFSET`), különben egy közben beeső feliratkozó
     * elcsúsztatná a lapozást, és valaki kimaradna a kampányból.
     *
     * @param  callable(list<array{email: string, contact_id: ?int}>, int): void  $callback
     *                                                                                       a köteg és az első elemének pozíciója
     */
    public function each(Campaign $campaign, int $size, callable $callback): void
    {
        $after = null;
        $position = 0;

        while (true) {
            $query = $this->query($campaign)->orderBy('email')->limit($size);

            if ($after !== null) {
                // `HAVING`, nem `WHERE`: a cím a `GROUP BY` kulcsa, és a Postgres a
                // csoportosítás UTÁNI szűrést itt fogadja el.
                $query->having('email', '>', $after);
            }

            $rows = array_map(
                static fn (object $row): array => [
                    'email' => (string) $row->email,
                    'contact_id' => $row->contact_id === null ? null : (int) $row->contact_id,
                ],
                $query->get()->all()
            );

            if ($rows === []) {
                return;
            }

            // A `false` visszatérés MEGÁLLÍTJA a bejárást (a materializálás így áll le, ha a
            // kampányt közben szüneteltették vagy visszavonták — review BN1).
            if ($callback($rows, $position) === false) {
                return;
            }

            $position += count($rows);
            $after = $rows[count($rows) - 1]['email'];
        }
    }

    /**
     * A CÉLZOTT címek EGY TÖMBBEN.
     *
     * SZÁNDÉKOSAN KORLÁTOS: a `$limit` fölött kivételt dob, nem hallgat. A korlátlan
     * változat volt az, ami 100 000 kontaktnál kiütötte a folyamatot (lásd az osztály
     * docblockját). A küldési út az `each()`-et használja; ez a felület előnézetéhez és a
     * teszthez van.
     *
     * @return list<array{email: string, contact_id: ?int}>
     */
    public function resolve(Campaign $campaign, int $limit = 10000): array
    {
        $all = [];

        $this->each($campaign, 1000, function (array $rows) use (&$all, $limit): void {
            $all = array_merge($all, $rows);

            if (count($all) > $limit) {
                throw new RuntimeException(sprintf(
                    'A célzás %d címnél többet ad; ekkora halmazt kötegelve kell olvasni (`each()`).',
                    $limit
                ));
            }
        });

        return $all;
    }

    /**
     * A CÍMZETTSZÁM — `COUNT`, nem `count(resolve())`.
     *
     * A `COUNT(*)` származtatott táblán fut: a `GROUP BY` miatt a CSOPORTOK számát kell
     * megkapni, nem az egyik csoport elemszámát.
     */
    public function estimate(Campaign $campaign): int
    {
        return (int) DB::query()->fromSub($this->query($campaign), 'celzott')->count();
    }

    /**
     * A CÉLZÁS EGYETLEN LEKÉRDEZÉSE: (lista ∪ szegmens) ∖ kizárás, címre csoportosítva.
     *
     * `UNION ALL`, nem `UNION`: a duplikátumot a `GROUP BY` szedi ki, és így a
     * `MIN(contact_id)` dönti el, melyik kontakt-sor képviseli a címet. A `UNION`
     * `(email, id)` PÁROKAT dedupolna — ugyanaz a cím két id-vel bent maradna, és a
     * címzett kétszer kapná meg a kampányt.
     */
    public function query(Campaign $campaign): QueryBuilder
    {
        $included = $this->union(
            (array) $campaign->target_lists,
            (array) $campaign->target_segments,
        );

        if ($included === null) {
            // NINCS CÉLZÁS = nincs címzett. Egy „minden kontakt" alapértelmezés itt azt
            // jelentené, hogy egy félig beállított kampány a TELJES közönségre menne ki.
            return DB::query()
                ->fromSub(
                    DB::query()->selectRaw('null::text as email, null::bigint as contact_id')->whereRaw('false'),
                    'forras'
                )
                ->selectRaw('email, MIN(contact_id) as contact_id')
                ->groupBy('email');
        }

        $query = DB::query()
            ->fromSub($included, 'forras')
            ->selectRaw('email, MIN(contact_id) as contact_id')
            ->groupBy('email');

        $excluded = $this->union(
            (array) $campaign->exclude_lists,
            (array) $campaign->exclude_segments,
        );

        if ($excluded !== null) {
            // KIVONÁS AZ UNIÓ UTÁN (lásd az osztály docblockját): a kizárt cím nem kerülhet
            // vissza azért, mert egy másik célzott listán is szerepel.
            $query->whereNotIn('email', DB::query()->fromSub($excluded, 'kizart')->select('email'));
        }

        return $query;
    }

    /**
     * A FORRÁSOK `UNION ALL`-ja, vagy `null`, ha nincs egy sem.
     *
     * @param  list<mixed>  $listUuids
     * @param  list<mixed>  $segmentUuids
     */
    private function union(array $listUuids, array $segmentUuids): ?QueryBuilder
    {
        $sources = [];

        $lists = $this->listQuery($listUuids);

        if ($lists !== null) {
            $sources[] = $lists;
        }

        foreach ($this->segmentQueries($segmentUuids) as $segment) {
            $sources[] = $segment;
        }

        if ($sources === []) {
            return null;
        }

        $union = array_shift($sources);

        foreach ($sources as $source) {
            $union->unionAll($source);
        }

        return $union;
    }

    /** @param list<mixed> $uuids */
    private function listQuery(array $uuids): ?QueryBuilder
    {
        $uuids = $this->uuids($uuids);

        if ($uuids === []) {
            return null;
        }

        $listIds = ContactList::query()->whereIn('uuid', $uuids)->pluck('id');

        if ($listIds->isEmpty()) {
            return null;
        }

        return $this->selectEmails(
            Contact::query()
                ->reachable()
                // A LEIRATKOZOTT TAGSÁG NEM TAGSÁG: a sor megmarad (a tény bizonyíték), de
                // a kampányba nem tartozik bele. Ugyanaz a szabály, mint a szegmens-motorban.
                ->whereHas('lists', fn (EloquentBuilder $query) => $query
                    ->whereIn('contact_lists.id', $listIds)
                    ->where('contact_list_memberships.status', 'subscribed'))
        );
    }

    /**
     * @param  list<mixed>  $uuids
     * @return list<QueryBuilder>
     */
    private function segmentQueries(array $uuids): array
    {
        $uuids = $this->uuids($uuids);

        if ($uuids === []) {
            return [];
        }

        $queries = [];

        foreach (Segment::query()->whereIn('uuid', $uuids)->get() as $segment) {
            // A SZEGMENS-MOTOR ugyanaz, amit a felület használ (B1): egy külön
            // kampány-oldali értelmezés azt jelentené, hogy a felületen mutatott
            // szegmens-tagság és a kampány címzettjei eltérnek.
            $queries[] = $this->selectEmails($this->segments->query((array) $segment->definition));
        }

        return $queries;
    }

    /**
     * A KÖZÖS OSZLOPLISTA — az `UNION ALL` ágainak azonos alakúnak KELL lenniük.
     *
     * A `reorder()` kötelező: a lista-lekérdezés és a `SegmentEngine` is `ORDER BY id`-vel
     * jön, az `UNION` ágain viszont a rendezés nem megengedett. Rendezni a KÜLSŐ lekérdezés
     * rendez (az `each()`-ben, címre).
     */
    private function selectEmails(EloquentBuilder $query): QueryBuilder
    {
        return $query
            ->reorder()
            ->select(['contacts.email as email', 'contacts.id as contact_id'])
            ->toBase();
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function uuids(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn ($value) => is_scalar($value) ? (string) $value : null,
            $values,
        )));
    }
}
