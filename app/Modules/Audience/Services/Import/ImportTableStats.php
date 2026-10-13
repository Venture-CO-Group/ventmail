<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use Illuminate\Support\Facades\DB;

/**
 * A lekérdezés-tervező STATISZTIKÁJÁNAK frissítése az import közben.
 *
 * ## A probléma, amit mértünk
 *
 * Az import chunkonként `WHERE email IN (…1000 cím…)` kereséssel nézi meg, melyik kontakt /
 * consent létezik már. A Postgres a statisztikából dönti el, melyik indexet használja — és az
 * import közben a tábla percek alatt nulláról százezres méretűre nő, a statisztika viszont
 * csak az autovacuum következő futásánál frissül (alapból percenként, 10% változás után). Az
 * elavult statisztikával a tervező az `(workspace_id, status)` indexet választotta, és az
 * `email`-t szűrőként alkalmazta: minden chunk-lekérdezés a workspace ÖSSZES kontaktján
 * végigment. 150 000 sornál a lekérdezések 18 ms-osak voltak, a futásidő négyzetesen nőtt
 * (300 000 sor: 400 s).
 *
 * Az `ANALYZE` olcsó (mintát vesz, nem olvassa végig a táblát), és a futó tranzakció
 * sorait is látja. Az import ezért a futás elején és húsz chunkonként lefuttatja a két
 * keresett táblára.
 *
 * Ez karbantartási nyelv (nem tenant-lekérdezés): a `ScopeBypassGuardTest` kivétel-listáján
 * szerepel, ugyanúgy, mint a `Metering` nyers `INSERT … ON CONFLICT`-ja.
 */
final class ImportTableStats
{
    public function refresh(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ANALYZE contacts');
        DB::statement('ANALYZE consents');
    }
}
