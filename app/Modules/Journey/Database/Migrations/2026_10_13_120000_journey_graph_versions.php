<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · 6. modul — JOURNEY VÁSZON: gráf + verziók (D5). EXPAND-ONLY.
 |
 | 1. `journeys.graph` (jsonb, NULL) — a szerkesztő VÁZLATA (csomópontok + élek). A NULL a
 |    v1.1 előtti, láncolt listás journey: az továbbra is a régi `next/else` úton fut.
 | 2. `journey_versions` — a PUBLIKÁLT gráf megváltoztathatatlan pillanatképe. Aktív
 |    journey módosítása ÚJ verziót hoz; a már bent lévő futások a sajátjukon haladnak.
 | 3. `journey_steps.journey_version_id` + `node_key` + `branches` + `executed_count` — a
 |    verzió lépései (a gráf csomópontjai), a többágú kimenetek és az ELŐRE ÖSSZESÍTETT
 |    „továbblépett" számláló (a csomópont-statisztika nem számol a futás-táblán).
 | 4. `journey_runs.journey_version_id` — melyik verzión halad a futás.
 | 5. `journeys.current_version_id` — az ÚJ belépések verziója.
 |
 | Minden új oszlop NULL-os vagy alapértékes: a rollback adatbázis-visszaállítás nélkül
 | működik, a régi kód az új oszlopokat egyszerűen nem látja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journeys', function (Blueprint $table): void {
            $table->jsonb('graph')->nullable();
        });

        Schema::create('journey_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journey_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->jsonb('graph');
            $table->string('graph_hash', 64);
            $table->foreignId('entry_step_id')->nullable()->constrained('journey_steps')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at');
            $table->timestamps();

            $table->unique(['journey_id', 'number']);
            $table->index(['workspace_id', 'journey_id']);
        });

        Schema::table('journeys', function (Blueprint $table): void {
            $table->foreignId('current_version_id')->nullable()->constrained('journey_versions')->nullOnDelete();
        });

        Schema::table('journey_steps', function (Blueprint $table): void {
            $table->foreignId('journey_version_id')->nullable()->constrained('journey_versions')->cascadeOnDelete();
            $table->string('node_key', 64)->nullable();
            // A TÖBBÁGÚ kimenet (split, A/B): `{kimenet: lépés-id}`. Az egy- és kétkimenetű
            // lépés a meglévő, FK-val őrzött `next_step_id` / `else_step_id` oszlopot használja.
            $table->jsonb('branches')->nullable();
            $table->unsignedBigInteger('executed_count')->default(0);

            $table->unique(['journey_version_id', 'node_key']);
        });

        Schema::table('journey_runs', function (Blueprint $table): void {
            $table->foreignId('journey_version_id')->nullable()->constrained('journey_versions')->nullOnDelete();
        });

        /*
         | A VERZIÓ-HIVATKOZÁS sem léphet át journey- vagy tenant-határt (ugyanaz az elv, mint a
         | `2026_09_14_091000_lock_journey_invariants` lánc-őre): a `BelongsToWorkspace` a sor
         | `workspace_id`-jét nézi, a HIVATKOZOTT verzióét nem.
         */
        DB::unprepared(<<<'SQL'
            create or replace function ventmail_journey_version_ref_guard() returns trigger as $$
            begin
                if new.journey_version_id is not null then
                    perform 1 from journey_versions v
                        where v.id = new.journey_version_id
                          and v.journey_id = new.journey_id
                          and v.workspace_id = new.workspace_id;

                    if not found then
                        raise exception
                            'A journey-verzió hivatkozás IDEGEN journey vagy tenant verziójára mutat (sor: %)', new.id
                            using errcode = 'check_violation';
                    end if;
                end if;

                return new;
            end;
            $$ language plpgsql;

            create trigger journey_steps_version_guard
                after insert or update of journey_version_id on journey_steps
                for each row execute function ventmail_journey_version_ref_guard();

            create trigger journey_runs_version_guard
                after insert or update of journey_version_id on journey_runs
                for each row execute function ventmail_journey_version_ref_guard();

            create or replace function ventmail_journey_current_version_guard() returns trigger as $$
            begin
                if new.current_version_id is not null then
                    perform 1 from journey_versions v
                        where v.id = new.current_version_id
                          and v.journey_id = new.id
                          and v.workspace_id = new.workspace_id;

                    if not found then
                        raise exception
                            'A journey aktuális verziója IDEGEN journey vagy tenant verziója (journey: %)', new.id
                            using errcode = 'check_violation';
                    end if;
                end if;

                return new;
            end;
            $$ language plpgsql;

            create trigger journeys_current_version_guard
                after insert or update of current_version_id on journeys
                for each row execute function ventmail_journey_current_version_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists journeys_current_version_guard on journeys;
            drop function if exists ventmail_journey_current_version_guard();
            drop trigger if exists journey_runs_version_guard on journey_runs;
            drop trigger if exists journey_steps_version_guard on journey_steps;
            drop function if exists ventmail_journey_version_ref_guard();
        SQL);

        Schema::table('journey_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('journey_version_id');
        });

        Schema::table('journey_steps', function (Blueprint $table): void {
            $table->dropUnique(['journey_version_id', 'node_key']);
            $table->dropConstrainedForeignId('journey_version_id');
            $table->dropColumn(['node_key', 'branches', 'executed_count']);
        });

        Schema::table('journeys', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_version_id');
        });

        Schema::dropIfExists('journey_versions');

        Schema::table('journeys', function (Blueprint $table): void {
            $table->dropColumn('graph');
        });
    }
};
