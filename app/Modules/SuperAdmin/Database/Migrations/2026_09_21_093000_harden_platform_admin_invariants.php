<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | C2 · A PLATFORM-ADMIN INVARIÁNSAI AZ ADATBÁZISBAN.
 |
 | Mindkettőt az alkalmazás is betartja — de az alkalmazás-szintű betartás egy `foreach`,
 | amit a következő belépési pont (kontroller, konzol-parancs, tömeges `update`) nem lát.
 | A review mindkettőt megmérte: a párhuzamos `start()` két aktív munkamenetet hagy, a
 | tömeges `update(['revoked_at' => null])` pedig egy visszavont csomag-emelést újraéleszt,
 | audit-sor nélkül. Ezek nem elméleti esetek: pont ezt a hibaosztályt (C1 · `Dunning`)
 | jelölte meg a korábbi review is.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | EGY ADMINNAK EGYSZERRE EGY NÉZETE.
         |
         | A `Impersonation::start()` lezárja a korábbiakat, de két párhuzamos hívás között
         | nincs zár: mérve két aktív sor marad, és onnantól sem az `active()`, sem az
         | írás-tilalom nem tudja megmondani, MELYIK tenant nézetében van az admin.
         |
         | Részleges unique index: a LEZÁRT munkamenetekből bármennyi lehet (az a történet),
         | nyitottból adminonként egy.
         */
        DB::statement('create unique index if not exists impersonation_sessions_one_open_per_user
            on impersonation_sessions (user_id) where ended_at is null');

        /*
         | A VISSZAVONÁS VÉGLEGES.
         |
         | A `revoked_at` nullázása (vagy egy visszavont override lejáratának kitolása) azt
         | jelentené, hogy a csomag-emelés a visszavonás UTÁN újraéled — audit-sor nélkül,
         | mert a `PlanOverrides::revoke()` az egyetlen hely, ami auditál. Ha az emelést
         | mégis meg kell adni, az ÚJ sor, új indokkal és új audit-nyommal.
         |
         | A trigger a `features` és a `send_quota` átírását is tiltja visszavonás után: a
         | „visszavontam, de közben át is írtam" állapot nem értelmezhető.
         */
        DB::statement("create or replace function plan_overrides_revocation_final() returns trigger as \$\$
            begin
                if old.revoked_at is not null then
                    if new.revoked_at is null then
                        raise exception 'A visszavont plan-override nem elesztheto ujra: adj uj sort, uj indokkal.';
                    end if;

                    if new.expires_at <> old.expires_at
                       or new.send_quota is distinct from old.send_quota
                       or new.features::text <> old.features::text
                       -- A MIÉRT VONTUK VISSZA is a tartalom része: ha utólag átírható,
                       -- a visszavonás indoka csendben megváltoztatható.
                       or new.reason is distinct from old.reason
                    then
                        raise exception 'A visszavont plan-override tartalma nem irhato at.';
                    end if;
                end if;

                return new;
            end;
        \$\$ language plpgsql");

        DB::statement('drop trigger if exists plan_overrides_revocation_final_trigger on plan_overrides');
        DB::statement('create trigger plan_overrides_revocation_final_trigger
            before update on plan_overrides
            for each row execute function plan_overrides_revocation_final()');

        /*
         | A 30 PERCES FELSŐ KORLÁT AZ ADATBÁZISBAN IS.
         |
         | A `Impersonation::MAX_MINUTES` alkalmazás-szintű, és a részleges unique index
         | csak a munkamenetek SZÁMÁT őrzi — a HOSSZÚSÁGÁT nem. Mérve: közvetlen
         | INSERT-tel 30 NAPOS nyitott munkamenet létrehozható volt. A támogatás percekig
         | néz egy fiókot, nem hetekig; a másik két invariánsnak van DB-őre, ennek is kell.
         |
         | A korlát 31 perc, nem 30: a `now()` a PHP- és a DB-oldalon nem ugyanaz a
         | pillanat, és egy másodperces csúszás nem lehet séma-hiba.
         */
        DB::statement("alter table impersonation_sessions
            add constraint impersonation_sessions_max_duration
            check (expires_at <= started_at + interval '31 minutes')");
    }

    public function down(): void
    {
        DB::statement('alter table impersonation_sessions drop constraint if exists impersonation_sessions_max_duration');
        DB::statement('drop trigger if exists plan_overrides_revocation_final_trigger on plan_overrides');
        DB::statement('drop function if exists plan_overrides_revocation_final()');
        DB::statement('drop index if exists impersonation_sessions_one_open_per_user');
    }
};
