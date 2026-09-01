<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | A review által feltárt rések zárása:
 |
 | 1. Az audit megváltoztathatatlansága eddig CSAK modell-eventen ült — a builder-út
 |    (`Model::query()->update()`) nem vált ki modell-eventet, tehát a napló átírható volt.
 |    Most DB-trigger tiltja; ez alól alkalmazás-kód nem tud kibújni.
 | 2. Az aktor az FK `null on delete` miatt eltűnhetett volna: denormalizált pillanatkép.
 | 3. A `unique(workspace_id, email)` Postgresben NEM korlátozza a NULL workspace_id sorokat,
 |    ezért a globális suppression duplikálódott volna minden FBL-feldolgozásnál.
 | 4. FK-oszlopokra index (a Postgres nem hozza létre automatikusan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_email')->nullable()->after('user_id');
            $table->string('actor_sso_subject')->nullable()->after('actor_email');
        });

        DB::statement('create or replace function audit_logs_immutable() returns trigger as $$
            begin
                raise exception \'Az audit-napló megváltoztathatatlan (%): a sorok nem módosíthatók és nem törölhetők.\', tg_op;
            end;
        $$ language plpgsql');

        DB::statement('create trigger audit_logs_immutable_trigger
            before update or delete on audit_logs
            for each row execute function audit_logs_immutable()');

        // Globális (workspace_id IS NULL) suppression: címenként egyetlen sor.
        // Előbb a meglévő duplikátumok összevonása — a legrégebbi sort tartjuk meg, mert
        // a tiltás KELETKEZÉSÉNEK ideje a jogilag releváns adat.
        DB::statement('delete from suppressions s using suppressions t
            where s.workspace_id is null and t.workspace_id is null
              and s.email = t.email and s.id > t.id');

        DB::statement('create unique index suppressions_global_email_unique
            on suppressions (email) where workspace_id is null');

        // A suppression csatorna-hatóköre: a marketing-leiratkozás SOHA nem blokkolhatja a
        // tranzakciós forgalmat (golden rule 4) — a PreSendGate 2. kapuja ezt olvassa.
        Schema::table('suppressions', function (Blueprint $table) {
            $table->string('applies_to')->default('all')->after('reason'); // all|marketing
        });

        // A Postgres nem indexeli az FK-oszlopot: enélkül a listanézetek és a cascade
        // delete is seq scannel megy.
        Schema::table('api_keys', fn (Blueprint $table) => $table->index('workspace_id'));
        Schema::table('smtp_credentials', fn (Blueprint $table) => $table->index('workspace_id'));
        Schema::table('ip_pools', fn (Blueprint $table) => $table->index('workspace_id'));

        // DKIM-rotáció: az előző kulcspár az átfedéses ideig érvényes marad (M0-4).
        Schema::table('domains', function (Blueprint $table) {
            $table->text('dkim_previous_public_key')->nullable()->after('dkim_previous_selector');
            $table->text('dkim_previous_private_key')->nullable()->after('dkim_previous_public_key');
        });

        // pool ↔ domain hozzárendelés (A2 elfogadási kritériuma).
        Schema::create('domain_ip_pool', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ip_pool_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['domain_id', 'ip_pool_id']);
            $table->index('ip_pool_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_ip_pool');

        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['dkim_previous_public_key', 'dkim_previous_private_key']);
        });

        Schema::table('ip_pools', fn (Blueprint $table) => $table->dropIndex(['workspace_id']));
        Schema::table('smtp_credentials', fn (Blueprint $table) => $table->dropIndex(['workspace_id']));
        Schema::table('api_keys', fn (Blueprint $table) => $table->dropIndex(['workspace_id']));

        Schema::table('suppressions', fn (Blueprint $table) => $table->dropColumn('applies_to'));

        DB::statement('drop index if exists suppressions_global_email_unique');
        DB::statement('drop trigger if exists audit_logs_immutable_trigger on audit_logs');
        DB::statement('drop function if exists audit_logs_immutable()');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['actor_email', 'actor_sso_subject']);
        });
    }
};
