<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | B1 · Közönség + consent — séma (49–57. kritérium).
 |
 | A tervezés két döntése, amit érdemes itt kimondani:
 |
 | 1. **A CÍM a kontakt azonosítója, és mindig NORMALIZÁLVA tárolódik.** A `(workspace_id,
 |    email)` egyedi: enélkül a `Anna@pelda.hu` és az `anna@pelda.hu` két kontakt lenne, két
 |    külön consent-állapottal — és a marketing kimenne arra, amelyik épp „megerősített". A
 |    normalizálás gazdája a `DomainNormalizer::listKey()`, ugyanaz, amit a suppression és a
 |    consent használ; a `email_hash` pedig PII-mentes keresést ad (peppered HMAC).
 |
 | 2. **A CONSENT-BIZONYÍTÉK append-only** (57. kritérium, az M0-2 security-review kérése). A
 |    `consents` sor a MATERIALIZÁLT AKTUÁLIS állapot — az, amit a PreSendGate 3. kapuja
 |    egyetlen lekérdezéssel megkérdezhet. A `consent_events` viszont a TÖRTÉNET: mikor,
 |    honnan, milyen jogalappal, milyen szöveggel. Egy jogi vitában nem az aktuális állapot a
 |    kérdés, hanem hogy VOLT-E érvényes hozzájárulás akkor, amikor küldtünk — és azt csak a
 |    történet tudja megmondani. Ezért a tábla UPDATE/DELETE-mentes, adatbázis-triggerrel:
 |    alkalmazás-kódból tilos, és a `DB::table()` sem kerüli meg.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_lists', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            // A lista SAJÁT feladója és leiratkozási szövege (Mailchimp-paritás): a
            // preference center ebből tudja megmutatni, MIRŐL iratkozik le a címzett.
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->boolean('double_opt_in')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('email');
            $table->string('email_hash', 64)->index();          // PII-mentes keresés
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();

            // Szabad attribútumok a szegmentáláshoz (`city`, `plan`, `ltv`…). JSONB, mert a
            // szegmens-motor ezekre szűr, és a séma tenantonként más.
            $table->jsonb('attributes')->default('{}');

            // `subscribed` | `unsubscribed` | `cleaned` | `pending` — a Mailchimp-paritás
            // állapotai. A `cleaned` a kézbesíthetetlen (hard bounce) cím.
            $table->string('status')->default('pending');

            // A ventOS-szinkron kulcsa (56. kritérium): kétirányú, idempotens upsert.
            $table->string('external_id')->nullable();

            // A címellenőrzés (A5) eredménye, ha lefutott: a szegmens-motor erre is szűr.
            $table->string('validation_verdict')->nullable();
            $table->float('validation_risk')->nullable();

            $table->timestampTz('subscribed_at')->nullable();
            $table->timestampTz('unsubscribed_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // A CÍM a kontakt azonosítója — tenantonként egyszer.
            $table->unique(['workspace_id', 'email']);
            // A ventOS-szinkron kulcsa is egyedi: két kontakt ugyanarra az `external_id`-ra
            // az idempotens upsertet kétértelművé tenné.
            $table->unique(['workspace_id', 'external_id']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('contact_list_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_list_id')->constrained()->cascadeOnDelete();

            // A LISTÁNKÉNTI állapot: valaki leiratkozhat EGY listáról anélkül, hogy a
            // többiről is. A preference center pontosan ezt kezeli (55. kritérium).
            $table->string('status')->default('subscribed');
            $table->timestampTz('subscribed_at')->nullable();
            $table->timestampTz('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['contact_list_id', 'contact_id']);
            $table->index(['workspace_id', 'contact_list_id', 'status']);
        });

        Schema::create('contact_tags', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('contact_tag_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['contact_tag_id', 'contact_id']);
            $table->index(['workspace_id', 'contact_id']);
        });

        Schema::create('segments', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // A SZEGMENS-DSL: `{"match":"all","rules":[{"field":"…","op":"…","value":…}]}`.
            // JSONB, mert a motor ezt értelmezi, és a felület ezt szerkeszti — SQL-t az
            // ügyfél SOHA nem ír (injektálás), a mezőnevek zárt listából jönnek.
            $table->jsonb('definition');

            // A becsült címzettszám gyorsítótára: a kampány-célzás (B2) ezt mutatja, és egy
            // milliós listán a valós idejű count() a felületet állítaná meg.
            $table->unsignedBigInteger('estimated_size')->nullable();
            $table->timestampTz('estimated_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'name']);
        });

        /*
         | CONSENT-BIZONYÍTÉK — APPEND-ONLY (57. kritérium).
         |
         | A `consents` sor az aktuális állapot; EZ a történet. Egy jogi vitában nem az a
         | kérdés, hogy MOST van-e hozzájárulás, hanem hogy VOLT-E akkor, amikor küldtünk.
         */
        Schema::create('consent_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consent_id')->nullable()->constrained()->nullOnDelete();

            // A CÍM itt is szerepel, mert a `consents` sor törölhető (GDPR), a bizonyíték
            // viszont nem — a hash-elt alak marad, a nyers cím redaktálható.
            $table->string('email')->nullable();
            $table->string('email_hash', 64)->index();

            // `requested` | `confirmed` | `revoked` | `imported` | `bounced_out`
            $table->string('type');
            $table->string('channel')->default('marketing');
            $table->string('legal_basis')->default('consent');
            $table->string('source')->nullable();

            // A BIZONYÍTÉK részletei. Nyers IP SOHA (53. kritérium): peppered HMAC.
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->string('policy_version')->nullable();
            $table->text('form_text')->nullable();               // amit a felhasználó LÁTOTT
            $table->jsonb('payload')->default('{}');

            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->index(['workspace_id', 'email_hash', 'occurred_at']);
            $table->index(['workspace_id', 'type']);
        });

        // A MEGVÁLTOZTATHATATLANSÁG adatbázis-szinten, nem modell-hookban: a hook a
        // `DB::table()`-t nem fogja, és egy bizonyíték-tábla pont attól bizonyíték, hogy
        // utólag nem írható át. Ugyanaz a minta, mint az `audit_logs`-nál.
        DB::statement('create or replace function consent_events_immutable() returns trigger as $$
            begin
                raise exception \'A consent-bizonyíték megváltoztathatatlan (%): a sorok nem módosíthatók és nem törölhetők.\', tg_op;
            end;
        $$ language plpgsql');

        DB::statement('create trigger consent_events_immutable_trigger
            before update or delete on consent_events
            for each row execute function consent_events_immutable()');

        Schema::create('forms', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_list_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('slug');

            // A mezők definíciója (címke, típus, kötelező-e) — a rendereléshez és a
            // validáláshoz egy forrásból.
            $table->jsonb('fields')->default('[]');

            // BOT-SZŰRÉS (54. kritérium): a honeypot mező NEVE tenantonként más, hogy egy
            // általános bot ne ismerje fel; a time-trap alsó határa másodpercben.
            $table->string('honeypot_field');
            $table->unsignedInteger('min_seconds')->default(3);

            $table->boolean('double_opt_in')->default(true);
            $table->string('policy_version')->nullable();
            $table->text('consent_text')->nullable();            // amit a felhasználó LÁT
            $table->string('redirect_url')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');

        DB::statement('drop trigger if exists consent_events_immutable_trigger on consent_events');
        DB::statement('drop function if exists consent_events_immutable()');

        Schema::dropIfExists('consent_events');
        Schema::dropIfExists('segments');
        Schema::dropIfExists('contact_tag_assignments');
        Schema::dropIfExists('contact_tags');
        Schema::dropIfExists('contact_list_memberships');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('contact_lists');
    }
};
