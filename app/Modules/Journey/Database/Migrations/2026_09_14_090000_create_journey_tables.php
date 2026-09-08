<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | B3 · Journey motor — séma (68–71. kritérium).
 |
 | ## A FUTÁS PERZISZTENS, és ez a lényeg
 |
 | Egy journey napokig–hetekig tart: „regisztráció után 3 nap, ha nem vásárolt, küldj
 | emlékeztetőt". Ez nem tarthat egy folyamatban, egy `sleep()`-pel — a worker újraindul, a
 | deploy lecseréli a kódot, a szerver átbootol. A futás ÁLLAPOTA ezért az adatbázisban van
 | (`journey_runs`), és a motor csak LÉPTETI: minden tick megkérdezi, hol áll, mi a
 | következő, esedékes-e.
 |
 | ## AZ IDEMPOTENCIA A LÉPÉS SZINTJÉN
 |
 | A tick ütemezőből jön, tehát ISMÉTLŐDHET (átfedő futás, újrapróbálás, kézi indítás).
 | „Kétszeri tick = egy hatás" (68. kritérium) csak akkor teljesül, ha a lépés
 | végrehajtása és az állapot előreléptetése EGY tranzakcióban, SOR-ZÁROLÁSSAL történik —
 | különben két párhuzamos tick ugyanazt a levelet kétszer küldi ki.
 |
 | A `journey_run_steps` tábla ezt teszi ellenőrizhetővé: minden végrehajtott lépés EGY sor,
 | egyedi kulccsal `(journey_run_id, journey_step_id, iteration)`. A duplikált beszúrás így
 | adatbázis-szinten hasal el, nem az alkalmazás jóindulatán múlik.
 |
 | ## A FAKE ÓRA (68.)
 |
 | A `wait` lépés `resume_at`-ot ír, és a motor az AKTUÁLIS időhöz méri. A tesztben a
 | `travel()` állítja az órát — a motor semmit nem tud arról, hogy „valódi" idő-e. Ez
 | szándékos: egy háromnapos várakozást nem lehet háromnapos teszttel ellenőrizni.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journeys', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // `draft` | `active` | `paused` | `archived`
            $table->string('status')->default('draft');

            /*
             | A TRIGGER (69. kritérium): `form_signup` | `tag_added` | `segment_entered` |
             | `api_event` | `inbound_email`.
             |
             | A `config` a triggerhez tartozó szűrő (melyik űrlap, melyik címke, melyik
             | szegmens, melyik esemény-név, melyik inbound route).
             */
            $table->string('trigger_type');
            $table->jsonb('trigger_config')->default('{}');

            /*
             | AZ ÚJRABELÉPÉS szabálya. Alapból TILOS, és ez tudatos: egy „kosárelhagyás"
             | journey-be a címzett naponta újra beléphetne, és minden belépés új
             | levélsorozatot indítana. A tenant engedélyezheti, de nem az alapérték.
             */
            $table->boolean('allow_reentry')->default(false);
            // Az újrabelépés legkisebb távolsága órában (ha engedélyezett).
            $table->unsignedInteger('reentry_cooldown_hours')->default(720);

            // A CÉL: ha a kontakt eléri, kilép (70. kritérium). A cél egy esemény-név vagy
            // egy szegmens-tagság — a `goal_config` mondja meg, melyik.
            $table->string('goal_type')->nullable();
            $table->jsonb('goal_config')->default('{}');

            $table->unsignedBigInteger('entered_count')->default(0);
            $table->unsignedBigInteger('completed_count')->default(0);
            $table->unsignedBigInteger('exited_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'status', 'trigger_type']);
        });

        Schema::create('journey_steps', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journey_id')->constrained()->cascadeOnDelete();

            // A LÉPÉSEK LÁNCOLT LISTA, nem tömb-index: egy beszúrás a közepén különben
            // minden futó példány pozícióját elcsúsztatná (a `journey_runs` a lépésre
            // hivatkozik, nem a sorszámára).
            $table->foreignId('next_step_id')->nullable()->constrained('journey_steps')->nullOnDelete();

            // `send_email` | `wait` | `condition` | `add_tag` | `remove_tag` | `exit`
            $table->string('type');
            $table->jsonb('config')->default('{}');

            // A CONDITION KÉT kimenete. A `next_step_id` az „igaz" ág (a lánc folytatása),
            // ez pedig a „hamis". Két külön oszlop, nem egy `branches` JSON: az FK
            // garantálja, hogy létező lépésre mutat.
            $table->foreignId('else_step_id')->nullable()->constrained('journey_steps')->nullOnDelete();

            // A SORREND csak a MEGJELENÍTÉSHEZ: a végrehajtás a láncot követi.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['journey_id', 'position']);
        });

        // A BELÉPÉSI PONT külön oszlop a journey-n, mert a lánc feje nem a legkisebb
        // `position` (a szerkesztő átrendezhet).
        Schema::table('journeys', function (Blueprint $table): void {
            $table->foreignId('first_step_id')->nullable()->after('goal_config')
                ->constrained('journey_steps')->nullOnDelete();
        });

        Schema::create('journey_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('current_step_id')->nullable()->constrained('journey_steps')->nullOnDelete();

            // A CÍM a belépés pillanatában — a kontakt később törölhető (GDPR), a futás
            // riportja viszont ne menne szét ettől.
            $table->string('email');
            $table->string('email_hash', 64)->index();

            // `active` | `waiting` | `completed` | `exited` | `failed`
            $table->string('status')->default('active');

            // MIÉRT lépett ki (70. kritérium): `goal_reached` | `unsubscribed` | `manual` |
            // `journey_archived` | `entitlement_lost`. A riportnak meg kell tudnia mondani.
            $table->string('exit_reason')->nullable();

            /*
             | AZ ESEDÉKESSÉG. A `wait` lépés ezt írja, és a motor ehhez méri az aktuális
             | időt — a tesztben a fake óra ugyanezt látja. `NULL` = azonnal esedékes.
             */
            $table->timestampTz('resume_at')->nullable();

            // A LÉPÉS-SZÁMLÁLÓ a végtelen ciklus ellen: egy condition, ami magára mutat,
            // különben örökké futna, és a worker sort tömné meg.
            $table->unsignedInteger('steps_taken')->default(0);

            // A journey futás közbeni adatai (pl. a trigger payloadja) — a `condition`
            // lépés ezekre is szűrhet.
            $table->jsonb('context')->default('{}');

            $table->timestampTz('entered_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            /*
             | EGY KONTAKT EGY JOURNEY-BEN EGYSZER — az AKTÍV futásra.
             |
             | Részleges egyedi index (`where status in (active, waiting)`): a befejezett
             | futások megmaradnak (a riport és az újrabelépési cooldown miatt), de
             | párhuzamosan futó példány EGY lehet. Enélkül egy űrlap kétszeri beküldése két
             | párhuzamos levélsorozatot indítana ugyanannak az embernek.
             */
            $table->index(['journey_id', 'status', 'resume_at']);
            $table->index(['workspace_id', 'email_hash']);
        });

        // A RÉSZLEGES EGYEDI INDEX csak nyers SQL-lel megy (a Blueprint nem tud `where`-t).
        DB::statement(
            'create unique index journey_runs_active_unique on journey_runs (journey_id, email) '
            ."where status in ('active', 'waiting')"
        );

        /*
         | A VÉGREHAJTOTT LÉPÉSEK — az IDEMPOTENCIA adatbázis-szintű őre (68. kritérium).
         |
         | Minden végrehajtott lépés EGY sor. A `(journey_run_id, journey_step_id, iteration)`
         | egyedi: két párhuzamos tick közül a második beszúrása elhasal, tehát a hatás
         | (levélküldés, címkézés) egyszer történik meg. Ez nem az alkalmazás jóindulatán
         | múlik — a `insertOrIgnore` visszatérési értéke DÖNTI EL, hogy a lépést mi
         | végezzük-e el.
         |
         | Az `iteration` azért kell, mert egy ciklikus journey (engedélyezett
         | újrabelépéssel) ugyanazt a lépést többször is végrehajthatja — de minden körben
         | csak egyszer.
         */
        Schema::create('journey_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journey_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journey_step_id')->constrained('journey_steps')->cascadeOnDelete();

            $table->unsignedInteger('iteration')->default(1);
            $table->string('outcome')->nullable();          // true|false a conditionnél
            $table->uuid('message_uuid')->nullable();
            $table->timestampTz('executed_at');
            $table->timestamps();

            $table->unique(['journey_run_id', 'journey_step_id', 'iteration'], 'journey_run_steps_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_run_steps');

        DB::statement('drop index if exists journey_runs_active_unique');

        Schema::dropIfExists('journey_runs');

        Schema::table('journeys', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('first_step_id');
        });

        Schema::dropIfExists('journey_steps');
        Schema::dropIfExists('journeys');
    }
};
