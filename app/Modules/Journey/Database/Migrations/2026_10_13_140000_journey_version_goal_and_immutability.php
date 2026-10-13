<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · 6. modul, review 1 — EXPAND-ONLY.
 |
 | 1. `journey_versions.goal_type` / `goal_config` (REV-B2): a CÉL a VERZIÓ része. Korábban a
 |    journey-szintű cél minden publikáláskor felülíródott (cél-csomópont nélkül NULL-ra), és a
 |    régi verzión haladó futások elveszítették a kilépési feltételüket — a vásárló tovább kapta
 |    a nurture-levelet. A futás a SAJÁT verziójának célját nézi; a verzió nélküli (v1.1 előtti
 |    láncon haladó) futás továbbra is a journey-szintű célt.
 | 2. A verzió MEGVÁLTOZTATHATATLANSÁGA az adatbázisban is (review N2): a modell-szintű őr
 |    nyers UPDATE-tel megkerülhető volt. Az `entry_step_id` egyszer írható (NULL → érték),
 |    csak a SAJÁT verzió lépésére.
 |
 |    KIVÉTEL (review 2, S1): az FK `ON DELETE SET NULL` átmenete — `published_by` → NULL (a
 |    felhasználó GDPR-/operátori törlése) és `entry_step_id` → NULL (a lépés törlése, pl. a
 |    workspace kemény törlésének kaszkádjában). Enélkül a trigger ezeket a törléseket
 |    elbuktatná. A NULL nem új tartalom, csak a hivatkozott sor eltűnése.
 |
 | 3. BACKFILL (review 2, S2): a már meglévő verziók a journey-szintű célt kapják, a trigger
 |    létrehozása ELŐTT. (A migráció nem került élesre: helyben bővítve — D161.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journey_versions', function (Blueprint $table): void {
            $table->string('goal_type')->nullable();
            $table->jsonb('goal_config')->nullable();
        });

        DB::statement(<<<'SQL'
            update journey_versions v
               set goal_type = j.goal_type,
                   goal_config = case when j.goal_type is null then null else j.goal_config end
              from journeys j
             where j.current_version_id = v.id
               and v.goal_type is null
        SQL);

        DB::unprepared(<<<'SQL'
            create or replace function ventmail_journey_version_immutable() returns trigger as $$
            begin
                if new.id is distinct from old.id
                   or new.uuid is distinct from old.uuid
                   or new.workspace_id is distinct from old.workspace_id
                   or new.journey_id is distinct from old.journey_id
                   or new.number is distinct from old.number
                   or new.graph is distinct from old.graph
                   or new.graph_hash is distinct from old.graph_hash
                   or (new.published_by is distinct from old.published_by and new.published_by is not null)
                   or new.published_at is distinct from old.published_at
                   or new.goal_type is distinct from old.goal_type
                   or new.goal_config is distinct from old.goal_config
                   or (old.entry_step_id is not null and new.entry_step_id is distinct from old.entry_step_id
                       and new.entry_step_id is not null) then
                    raise exception 'A publikált journey-verzió megváltoztathatatlan (verzió: %)', old.id
                        using errcode = 'check_violation';
                end if;

                if new.entry_step_id is not null then
                    perform 1 from journey_steps s
                        where s.id = new.entry_step_id
                          and s.journey_version_id = new.id
                          and s.workspace_id = new.workspace_id;

                    if not found then
                        raise exception 'A verzió belépési lépése IDEGEN verzióhoz tartozik (verzió: %)', new.id
                            using errcode = 'check_violation';
                    end if;
                end if;

                return new;
            end;
            $$ language plpgsql;

            create trigger journey_versions_immutable
                before update on journey_versions
                for each row execute function ventmail_journey_version_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists journey_versions_immutable on journey_versions;
            drop function if exists ventmail_journey_version_immutable();
        SQL);

        Schema::table('journey_versions', function (Blueprint $table): void {
            $table->dropColumn(['goal_type', 'goal_config']);
        });
    }
};
