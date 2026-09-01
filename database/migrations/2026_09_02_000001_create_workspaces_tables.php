<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Tenancy: a workspace a ventMail tenant-egysége. A tagság a ventMail SAJÁT
 | workspace_members táblájából dől el — a ventSSO tokenből SOHA (golden rule 1, 6).
 | Sub-account: parent ↔ child kapcsolat a workspace_parents táblában.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();          // publikus azonosító
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('plan')->default('free');
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
            $table->softDeletes();                    // GDPR-releváns: nem hard delete
        });

        Schema::create('workspace_parents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('child_workspace_id')->constrained('workspaces')->cascadeOnDelete();
            // A parent explicit jogosultságai a child felett (nyers adat csak itt engedve).
            $table->jsonb('permissions')->default('{}');
            $table->timestamps();

            $table->unique(['parent_workspace_id', 'child_workspace_id']);
            $table->unique('child_workspace_id'); // egy childnak legfeljebb egy parentje van
        });

        // Önmaga szülője nem lehet; a spec 3.2 EGYSZINTŰ ügynökség-modellt ír le, ezért a
        // többszintű lánc (A→B→C) és a kör (A→B→A) sem alakulhat ki: aki már child,
        // az nem lehet parent, és fordítva.
        DB::statement('alter table workspace_parents add constraint workspace_parents_not_self
            check (parent_workspace_id <> child_workspace_id)');

        DB::statement('create or replace function workspace_parents_single_level() returns trigger as $$
            begin
                if exists (select 1 from workspace_parents where child_workspace_id = new.parent_workspace_id) then
                    raise exception \'A sub-account hierarchia egyszintű: egy child nem lehet parent.\';
                end if;

                if exists (select 1 from workspace_parents where parent_workspace_id = new.child_workspace_id) then
                    raise exception \'A sub-account hierarchia egyszintű: egy parent nem lehet child.\';
                end if;

                return new;
            end;
        $$ language plpgsql');

        DB::statement('create trigger workspace_parents_single_level_trigger
            before insert or update on workspace_parents
            for each row execute function workspace_parents_single_level()');

        Schema::create('workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->jsonb('roles')->default('[]');   // owner|admin|developer|editor|author|analyst
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        DB::statement('drop trigger if exists workspace_parents_single_level_trigger on workspace_parents');
        DB::statement('drop function if exists workspace_parents_single_level()');
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspace_parents');
        Schema::dropIfExists('workspaces');
    }
};
