<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | AZ AUDIT-NAPLÓ LEJÁRATA — az EGYETLEN törlési út, és az adatbázisban van.
 |
 | Eddig a `audit_logs_immutable()` trigger MINDEN DELETE-et elutasított. Ez helyes védelem
 | volt, de egy másik szabályt megszegett: a napló nem lehet örök (GDPR 5(1)(e)), és a
 | `context` szabad szöveges mezőin PII is bekerülhet, amit minta-alapú redakció nem fog meg.
 |
 | A megnyitott ajtó SZŰK, és két zárral:
 |
 | 1. SZÁNDÉK-JELZÉS: kizárólag `ventmail.audit_prune = 'on'` session-változó mellett, amit
 |    egyedül a `AuditLog::whileRetentionPruning()` állít be — ugyanaz a minta, mint a
 |    `ventmail.platform_write` a globális suppressionnél. Egy sima `DELETE FROM audit_logs`
 |    (kézből, kontrollerből, elrontott relációs cascade-ből) továbbra is elbukik.
 |
 | 2. IDŐBELI ALSÓ HATÁR: a sor legalább 365 napos legyen. Ez NEM a configból jön, és ez a
 |    lényeg: egy elgépelt `AUDIT_RETENTION_DAYS=1` így sem törölheti a friss naplót — épp
 |    azt, ami egy folyamatban lévő incidensnél a legértékesebb. Az alkalmazás és az
 |    adatbázis EGYMÁSTÓL FÜGGETLENÜL korlátoz.
 |
 | Az UPDATE tilalma változatlan: a lejárat TÖRÖL, nem átír. Egy „anonimizáló UPDATE" utat
 | megnyitni azt jelentené, hogy a napló utólag szerkeszthető — pont az, amit a tábla tilt.
 */
return new class extends Migration
{
    /** A DB-oldali, configgal NEM felülírható alsó határ. */
    private const FLOOR_DAYS = 365;

    public function up(): void
    {
        /*
         | INDEX A LEJÁRAT-KERESÉSHEZ.
         |
         | A meglévő index `(workspace_id, created_at)` — a retenciós lekérdezés viszont
         | workspace-től FÜGGETLENÜL keres (`where created_at < …`), tehát azt nem
         | használhatja: mérve `Seq Scan` + `Sort`, és a parancs ezt kötegenként (500 sor)
         | ismétli. Egy millió lejárt sor így kétezer teljes tábla-bejárást jelentene egy
         | folyamatosan növő táblán.
         */
        DB::statement('create index if not exists audit_logs_created_at_id_index
            on audit_logs (created_at, id)');

        DB::statement("create or replace function audit_logs_immutable() returns trigger as \$\$
            begin
                if tg_op = 'UPDATE'
                   and to_jsonb(new) - 'workspace_id' - 'user_id' = to_jsonb(old) - 'workspace_id' - 'user_id'
                   and (new.workspace_id is null or new.workspace_id = old.workspace_id)
                   and (new.user_id is null or new.user_id = old.user_id)
                   -- AZ FK-NULLÁZÁS CSAK AKKOR, HA A HIVATKOZOTT SOR TÉNYLEG ELTŰNT.
                   --
                   -- Enélkül a felmentés egy ELREJTÉSI útvonal is volt: egy nyers
                   -- `update(workspace_id = null)` a `platform.impersonation_started` sort
                   -- kivette a tenant naplójából (mérve), vagyis épp azt a tulajdonságot
                   -- vette el, amiért a sor a tenanthoz tartozik (a tenant IS LÁTJA).
                   -- A törlés és az elrejtés alakja ugyanaz — a különbség az, hogy törlés
                   -- után a hivatkozott workspace/user MÁR NEM LÉTEZIK.
                   and (new.workspace_id is not null or old.workspace_id is null
                        or not exists (select 1 from workspaces where id = old.workspace_id))
                   and (new.user_id is not null or old.user_id is null
                        or not exists (select 1 from users where id = old.user_id))
                then
                    -- Kizárólag az FK-k nullázása (workspace/user törlése). Az aktor-pillanatkép
                    -- oszlopai érintetlenek maradnak, tehát a nyom nem vész el.
                    return new;
                end if;

                if tg_op = 'DELETE'
                   and coalesce(current_setting('ventmail.audit_prune', true), '') = 'on'
                   and old.created_at < now() - interval '".self::FLOOR_DAYS." days'
                then
                    -- RETENCIÓS LEJÁRAT: szándék-jelzéssel ÉS az alsó időhatáron túl.
                    return old;
                end if;

                raise exception 'Az audit-naplo megvaltoztathatatlan (%): a sorok nem modosithatok, es csak a retencios lejarat torolheti oket.', tg_op;
            end;
        \$\$ language plpgsql");
    }

    public function down(): void
    {
        DB::statement('drop index if exists audit_logs_created_at_id_index');

        DB::statement("create or replace function audit_logs_immutable() returns trigger as \$\$
            begin
                if tg_op = 'UPDATE'
                   and to_jsonb(new) - 'workspace_id' - 'user_id' = to_jsonb(old) - 'workspace_id' - 'user_id'
                   and (new.workspace_id is null or new.workspace_id = old.workspace_id)
                   and (new.user_id is null or new.user_id = old.user_id)
                then
                    return new;
                end if;

                raise exception 'Az audit-naplo megvaltoztathatatlan (%): a sorok nem modosithatok es nem torolhetok.', tg_op;
            end;
        \$\$ language plpgsql");
    }
};
