<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * `CREATE INDEX CONCURRENTLY` — az INVALID maradvány kezelésével (v1.1 · 5. modul, review NIT).
 *
 * Egy megszakadt (timeout, deploy-leállítás, egyediség-sértés) párhuzamos index-építés INVALID
 * indexet hagy maga után. Az `if not exists` ezt LÉTEZŐNEK látja, és csendben átlépi: a migráció
 * „sikeres", a lekérdező pedig soha nem használja az indexet. Ezért előbb a `pg_index.indisvalid`
 * dönt: az érvénytelen maradvány eldobódik, és újraépül.
 *
 * A `CONCURRENTLY` tranzakción belül nem futhat (a migráció `$withinTransaction = false`); ha a
 * hívó mégis tranzakcióban van (teszt), sima építés fut.
 */
final class ConcurrentIndex
{
    /**
     * @param  string  $name  az index neve
     * @param  string  $definition  a `create index <név>` UTÁNI rész, pl. `on contacts (workspace_id, created_at)`
     */
    public static function create(string $name, string $definition): void
    {
        $concurrently = DB::transactionLevel() === 0 ? ' concurrently' : '';

        $valid = DB::selectOne(
            'select i.indisvalid from pg_index i join pg_class c on c.oid = i.indexrelid '
            .'where c.relname = ? and pg_table_is_visible(c.oid)',
            [$name],
        );

        if ($valid !== null && ! (bool) $valid->indisvalid) {
            DB::statement("drop index{$concurrently} if exists {$name}");
        }

        DB::statement("create index{$concurrently} if not exists {$name} {$definition}");
    }
}
