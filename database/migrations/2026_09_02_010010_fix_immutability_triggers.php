<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | A security-review két rése:
 |
 | 1. Az audit-trigger LEHETETLENNÉ tette a workspace és a user törlését: a `SET NULL` FK
 |    maga is UPDATE a hivatkozó táblán, amin a trigger elhasalt. Így nem volt GDPR Art. 17
 |    törlési út. A trigger mostantól átengedi azt az UPDATE-et, amely KIZÁRÓLAG az FK-kat
 |    nullázza — az aktor-pillanatkép (actor_email, actor_sso_subject) miatt a nyom megmarad.
 |
 | 2. A TRUNCATE nem vált ki row-triggert: statement-trigger is kell.
 |
 | 3. A platform-szintű (workspace_id IS NULL) suppression a builder-úton
 |    (Model::query()->update()/delete()) módosítható és törölhető volt, mert a modell-event
 |    ott nem fut le. DB-trigger tiltja, és csak a session-GUC-kal jelzett szándékos
 |    platform-írás alól ad felmentést — ezt az ::onPlatform() állítja be.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("create or replace function audit_logs_immutable() returns trigger as \$\$
            begin
                if tg_op = 'UPDATE'
                   and to_jsonb(new) - 'workspace_id' - 'user_id' = to_jsonb(old) - 'workspace_id' - 'user_id'
                   and (new.workspace_id is null or new.workspace_id = old.workspace_id)
                   and (new.user_id is null or new.user_id = old.user_id)
                then
                    -- Kizárólag az FK-k nullázása (workspace/user törlése). Az aktor-pillanatkép
                    -- oszlopai érintetlenek maradnak, tehát a nyom nem vész el.
                    return new;
                end if;

                raise exception 'Az audit-naplo megvaltoztathatatlan (%): a sorok nem modosithatok es nem torolhetok.', tg_op;
            end;
        \$\$ language plpgsql");

        DB::statement("create or replace function audit_logs_no_truncate() returns trigger as \$\$
            begin
                raise exception 'Az audit-naplo nem urítheto (TRUNCATE).';
            end;
        \$\$ language plpgsql");

        DB::statement('drop trigger if exists audit_logs_no_truncate_trigger on audit_logs');
        DB::statement('create trigger audit_logs_no_truncate_trigger
            before truncate on audit_logs
            for each statement execute function audit_logs_no_truncate()');

        // A platform-szintű sorok védelme: a modell-event a builder-úton nem fut le.
        DB::statement("create or replace function suppressions_platform_guard() returns trigger as \$\$
            declare
                target_workspace bigint;
            begin
                target_workspace := coalesce(old.workspace_id, new.workspace_id);

                if target_workspace is null
                   and coalesce(current_setting('ventmail.platform_write', true), 'off') <> 'on'
                then
                    raise exception 'A platform-szintu suppression csak szandekos platform-irasbol modosithato vagy torolheto.';
                end if;

                return coalesce(new, old);
            end;
        \$\$ language plpgsql");

        DB::statement('drop trigger if exists suppressions_platform_guard_trigger on suppressions');
        DB::statement('create trigger suppressions_platform_guard_trigger
            before update or delete on suppressions
            for each row execute function suppressions_platform_guard()');
    }

    public function down(): void
    {
        DB::statement('drop trigger if exists suppressions_platform_guard_trigger on suppressions');
        DB::statement('drop function if exists suppressions_platform_guard()');
        DB::statement('drop trigger if exists audit_logs_no_truncate_trigger on audit_logs');
        DB::statement('drop function if exists audit_logs_no_truncate()');
    }
};
