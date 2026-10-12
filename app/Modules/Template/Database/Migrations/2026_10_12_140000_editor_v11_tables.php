<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · 4. modul — drag-and-drop szerkesztő és sablonkönyvtár. EXPAND-ONLY.
 |
 | - `templates.styles` (nullable jsonb): a GLOBÁLIS stílus (betű, színek, gomb, szélesség).
 |   `null` = a fordító eddigi alapértelmezése, bitre azonos kimenettel (a golden fixture
 |   ezt őrzi), tehát a meglévő sablonok kinézete nem változik.
 | - `templates.starter_key` (nullable): melyik indulósablonból jött — csak tájékoztató.
 | - `template_versions`: MEGVÁLTOZTATHATATLAN pillanatképek. A visszaállítás ÚJ verziót ír,
 |   a régiek megmaradnak.
 | - `template_saved_blocks`: a workspace mentett blokkjai / szakaszai.
 | - `template_assets`: a feltöltött képek nyilvántartása (a bájtok az `AssetStore` mögött).
 |
 | A marketing-trigger FÜGGVÉNYE szélesedik: a `footer` blokk is leiratkozó elem (a
 | leiratkozó és a beállítás-link a láblécben KÖTELEZŐEN benne van). Szűkítés nincs: ami
 | eddig átment, most is átmegy. A `down()` a korábbi függvénytörzset állítja vissza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table): void {
            $table->jsonb('styles')->nullable();
            $table->string('starter_key', 64)->nullable();
        });

        Schema::create('template_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('name');
            $table->string('subject')->nullable();
            $table->string('preheader')->nullable();
            $table->jsonb('blocks')->default('[]');
            $table->jsonb('styles')->nullable();
            // MIÉRT jött létre: `save` (kézi), `autosave` (időszakos), `restore`, `create`.
            $table->string('reason', 32)->default('save');
            $table->unsignedInteger('restored_from')->nullable();
            $table->string('content_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['template_id', 'number']);
            $table->index(['workspace_id', 'template_id']);
        });

        Schema::create('template_saved_blocks', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // `section` (teljes szakasz) vagy `block` (egy tartalmi blokk).
            $table->string('kind', 16);
            $table->jsonb('block');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'kind']);
        });

        Schema::create('template_assets', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('driver', 32);
            $table->string('path', 255);
            $table->string('url', 512);
            $table->string('mime', 64);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('original_name', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            create or replace function ventmail_templates_marketing_guard() returns trigger as $$
            begin
                if new.marketing is true
                   and not jsonb_path_exists(new.blocks, '$.**.type ? (@ == "unsubscribe" || @ == "footer")')
                then
                    raise exception
                        'A marketing sablonban KÖTELEZŐ a leiratkozó blokk (RFC 8058, golden rule 8): %',
                        new.id
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$ language plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function ventmail_templates_marketing_guard() returns trigger as $$
            begin
                if new.marketing is true
                   and not jsonb_path_exists(new.blocks, '$.**.type ? (@ == "unsubscribe")')
                then
                    raise exception
                        'A marketing sablonban KÖTELEZŐ a leiratkozó blokk (RFC 8058, golden rule 8): %',
                        new.id
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$ language plpgsql;
        SQL);

        Schema::dropIfExists('template_assets');
        Schema::dropIfExists('template_saved_blocks');
        Schema::dropIfExists('template_versions');

        Schema::table('templates', function (Blueprint $table): void {
            $table->dropColumn(['styles', 'starter_key']);
        });
    }
};
