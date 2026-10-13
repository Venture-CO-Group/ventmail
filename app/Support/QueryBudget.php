<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * LEKÉRDEZÉSI KERET (v1.1 · 5. modul, review S2) — a felhasználó által összerakott, drága
 * lekérdezések (szegmens-előnézet és -mérés, közönség-dashboard) felső időkorlátja.
 *
 * A munka egy tranzakcióban fut, `SET LOCAL statement_timeout`-tal: a korlát csak erre a
 * tranzakcióra érvényes, a kapcsolat többi lekérdezését nem érinti. A Postgres
 * `57014` (query_canceled) hibája `QueryTooExpensive` lesz — a hívó 422-t ad („túl költséges
 * szegmens"), nem 500-at, és egy elszabadult szegmens nem foglal le egy FPM-workert percekig.
 *
 * Szándékosan nem a modulokban él: a `SET LOCAL` séma-szintű utasítás, nem tenant-lekérdezés.
 */
class QueryBudget
{
    /** Az alapértelmezett keret (ms). */
    public const DEFAULT_MS = 5000;

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function run(callable $work, ?int $milliseconds = null): mixed
    {
        $budget = max(1, (int) ($milliseconds ?? config('studio.query_budget_ms', self::DEFAULT_MS)));

        try {
            return DB::transaction(function () use ($work, $budget) {
                DB::statement('set local statement_timeout = '.$budget);

                return $work();
            });
        } catch (QueryException $e) {
            if ((string) ($e->errorInfo[0] ?? $e->getCode()) === '57014') {
                throw new QueryTooExpensive('A lekérdezés túl költséges.', 0, $e);
            }

            throw $e;
        }
    }
}
