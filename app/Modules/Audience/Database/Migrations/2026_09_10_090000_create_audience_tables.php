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
            /*
             | A `workspace_id` NULLABLE és `nullOnDelete` — nem `cascadeOnDelete`.
             |
             | `cascade` mellett a workspace hard delete-je DELETE-et jelent EZEN a táblán, amit
             | a megváltoztathatatlansági trigger (helyesen) elhasal — vagyis egyetlen
             | consent-esemény után a workspace SOHA többé nem lett volna törölhető (mérve).
             | Egy bizonyíték-tábla nem akadályozhatja meg a törlési jog gyakorlását.
             |
             | `SET NULL` mellett a bizonyíték MEGMARAD (platform-szinten, tenant elől
             | elrejtve), a workspace pedig törölhető. Ugyanaz a döntés, mint az
             | `audit_logs`-nál — és a trigger felmentése pontosan erre az UPDATE-re szól.
             */
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('consent_id')->nullable()->constrained()->nullOnDelete();

            /*
             | CSAK HASH — nyers cím ITT NINCS, és nem is lehet.
             |
             | A tábla append-only, TRIGGERREL védve: ami ide bekerül, azt utólag SEM
             | javítani, SEM törölni nem lehet. Egy nyers cím tehát a GDPR 17. cikk szerinti
             | törlést VÉGLEGESEN teljesíthetetlenné tenné — a review meg is mérte: az
             | `erase()` után két `consent_events` sorban ott maradt a cím, és a redakciós
             | UPDATE `SQLSTATE[P0001] … megváltoztathatatlan (UPDATE)`-tel elhasalt.
             |
             | A peppered hash a BIZONYÍTÁSHOZ elég (ugyanaz a cím-e, mint amiről a vita
             | szól), az AZONOSÍTÁSHOZ nem — és pontosan ez a cél: a bizonyíték megmarad, az
             | érintett azonosíthatósága eltűnik.
             */
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

        /*
         | A MEGVÁLTOZTATHATATLANSÁG adatbázis-szinten, nem modell-hookban: a hook a query
         | buildert nem fogja, és egy bizonyíték-tábla pont attól bizonyíték, hogy utólag nem
         | írható át.
         |
         | A MINTA az `audit_logs`-é — de a JAVÍTOTT változata
         | (`2026_09_02_010010_fix_immutability_triggers`). A naiv, feltétel nélküli trigger
         | ugyanis KÉT hibát hoz vissza, amit a platform egy korábbi security-review után már
         | kijavított, és a review mindkettőt megmérte ezen a táblán:
         |
         |  1. **A TRUNCATE nem vált ki row-triggert.** Sor-szintű őrrel a bizonyíték egyetlen
         |     `truncate consent_events`-tel eltűnt (mérve: 1 → 0 sor), miközben az
         |     `audit_logs` ugyanezt helyesen elutasítja. Kell egy STATEMENT-szintű őr is.
         |
         |  2. **Az FK-nullázás maga is UPDATE.** A `consent_id` `nullOnDelete`, tehát egy
         |     consent-sor törlése UPDATE-et jelent EZEN a táblán — a feltétel nélküli trigger
         |     ezt elhasalta, és ezzel a workspace hard delete-je is lehetetlenné vált
         |     (mérve). Vagyis egyetlen consent-esemény után NEM LETT VOLNA GDPR Art. 17
         |     törlési út — pont annak a jognak az útja, amit ez a tábla szolgálni hivatott.
         |
         | A felmentés SZŰK: kizárólag az az UPDATE megy át, ami CSAK a `consent_id`-t (és a
         | `workspace_id`-t) nullázza; a bizonyíték érdemi mezői érintetlenek maradnak, tehát
         | a nyom nem vész el.
         */
        DB::statement("create or replace function consent_events_immutable() returns trigger as \$\$
            begin
                if tg_op = 'UPDATE'
                   and to_jsonb(new) - 'consent_id' - 'workspace_id' = to_jsonb(old) - 'consent_id' - 'workspace_id'
                   and (new.consent_id is null or new.consent_id = old.consent_id)
                   and (new.workspace_id is null or new.workspace_id = old.workspace_id)
                then
                    -- Kizárólag az FK-k nullázása (consent/workspace törlése). A bizonyíték
                    -- érdemi mezői (típus, időpont, jogalap, hash-ek) érintetlenek.
                    return new;
                end if;

                raise exception 'A consent-bizonyíték megváltoztathatatlan (%): a sorok nem módosíthatók és nem törölhetők.', tg_op;
            end;
        \$\$ language plpgsql");

        DB::statement('create trigger consent_events_immutable_trigger
            before update or delete on consent_events
            for each row execute function consent_events_immutable()');

        DB::statement("create or replace function consent_events_no_truncate() returns trigger as \$\$
            begin
                raise exception 'A consent-bizonyíték nem üríthető (TRUNCATE).';
            end;
        \$\$ language plpgsql");

        DB::statement('create trigger consent_events_no_truncate_trigger
            before truncate on consent_events
            for each statement execute function consent_events_no_truncate()');

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

            /*
             | NINCS `double_opt_in` KAPCSOLÓ — sem itt, sem a listán.
             |
             | Volt, és a review megmérte, mit jelentett: `double_opt_in = false` mellett az
             | űrlap-beküldés AZONNAL `confirmed` consentet adott, megerősítő link nélkül —
             | vagyis a golden rule 4 („marketing csak double opt-in consenttel") egy
             | tenant által átkapcsolható booleanná vált, ADR nélkül.
             |
             | Ha egyszer üzleti döntés lesz az egyszeres opt-in, azt ADR-rel és a golden
             | rule módosításával kell meghozni — nem egy csendes oszloppal, amit senki nem
             | vesz észre a felületen.
             */

            // BOT-SZŰRÉS (54. kritérium): a honeypot mező NEVE tenantonként más, hogy egy
            // általános bot ne ismerje fel; a time-trap alsó határa másodpercben.
            $table->string('honeypot_field');
            $table->unsignedInteger('min_seconds')->default(3);

            $table->string('policy_version')->nullable();
            $table->text('consent_text')->nullable();            // amit a felhasználó LÁT
            $table->string('redirect_url')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            /*
             | A SLUG GLOBÁLISAN EGYEDI, nem tenantonként.
             |
             | Tenantonkénti egyediséggel a `/f/{slug}` feloldás kétértelmű lett: a
             | `limit(2)` + „ha nem pontosan egy, csendben köszönő oldal" minta miatt bárki,
             | aki ismert egy publikus űrlap-URL-t, felvehetett ugyanazzal a sluggal egy sajátot
             | — és ezzel ÉSZREVÉTLENÜL kilőtte az áldozat űrlapját (mérve: HTTP 200 a
             | köszönő oldallal, nulla kontakt MINDKÉT workspace-ben).
             |
             | A `limit(2)`-minta a domaineknél azért elég, mert ott az egyediséget a
             | VERIFIKÁCIÓ adja (DNS-rekord). A slugnál semmi nem adja — tehát az adatbázisnak
             | kell adnia.
             */
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');

        DB::statement('drop trigger if exists consent_events_no_truncate_trigger on consent_events');
        DB::statement('drop trigger if exists consent_events_immutable_trigger on consent_events');
        DB::statement('drop function if exists consent_events_no_truncate()');
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
