<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | B2 · Kampányok — séma (62–67. kritérium).
 |
 | ## A CÍMZETT-HALMAZ MATERIALIZÁLÓDIK (63. kritérium)
 |
 | A célzás lista ∪ szegmens MÍNUSZ kizárás, és a feloldott halmazt TÁBLÁBA írjuk
 | (`campaign_recipients`), nem csak lekérdezésként tartjuk. Három okból:
 |
 |  1. **Idempotencia.** Egy megszakadt küldés folytatható, és a folytatás nem küld
 |     kétszer. Lekérdezés-alapú célzásnál a közben módosult közönség miatt a második
 |     futás MÁS halmazt látna — valaki kétszer kapja meg, valaki egyszer sem.
 |  2. **A becsült szám és a valóság egyezik.** A felület a küldés előtt számot mutat; ha a
 |     küldés újra kérdezné a szegmenst, a kettő eltérne, és a kampány-riport hazudna.
 |  3. **A/B variáns-hozzárendelés.** A variánst a címzetthez KELL kötni, különben egy
 |     újrapróbálás más variánst adna ugyanannak az embernek, és a mérés értelmét veszti.
 |
 | ## AZ IDŐZÓNA A TENANTÉ (65. kritérium)
 |
 | A `scheduled_at` UTC-ben tárolódik (a Postgres `timestamptz` amúgy is azt tesz), a
 | `timezone` viszont a MEGJELENÍTÉSHEZ és az ÚJRASZÁMÍTÁSHOZ kell. „Kedd 9:00" egy
 | budapesti ügyfélnek nem ugyanaz az UTC-pillanat nyáron és télen — ha csak az UTC-t
 | tároljuk, a nyári időszámítás váltása után a kampány egy órával elcsúszik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('subject')->nullable();
            $table->string('preheader')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();

            // `draft` | `scheduled` | `sending` | `paused` | `sent` | `canceled`
            $table->string('status')->default('draft');

            $table->timestampTz('scheduled_at')->nullable();
            // A tenant időzónája — a `scheduled_at` UTC, ez a megjelenítés és az
            // újraszámítás alapja (lásd a fájl fejlécét).
            $table->string('timezone')->default('UTC');

            // CÉLZÁS: listák és szegmensek uniója, MÍNUSZ a kizárások.
            $table->jsonb('target_lists')->default('[]');
            $table->jsonb('target_segments')->default('[]');
            $table->jsonb('exclude_lists')->default('[]');
            $table->jsonb('exclude_segments')->default('[]');

            // A BECSÜLT szám és a mérés pillanata: a felület ezt mutatja a küldés előtt.
            $table->unsignedBigInteger('estimated_recipients')->nullable();
            $table->timestampTz('estimated_at')->nullable();

            // A VALÓDI számok. A `sent` a kiküldött üzenetek száma, nem a címzetteké: egy
            // címzett egy üzenetet kap, de az újrapróbálás nem növeli a számot.
            $table->unsignedBigInteger('recipient_count')->default(0);
            $table->unsignedBigInteger('sent_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);

            /*
             | AZ A/B GYŐZTES AUTOMATA KIHIRDETÉSE (64. kritérium).
             |
             | A kritérium a HÍVÓ döntése (`open_rate` a nyitásra, `click_rate` a klikkre), és
             | rögzül: egy fél évvel későbbi „miért ez lett a győztes?" kérdésre a riportnak
             | válaszolnia kell. A `null` azt jelenti, hogy nincs automata kihirdetés — a
             | tenant kézzel dönt.
             |
             | A VÁRAKOZÁS azért kell, mert a nyitás és a klikk KÉSVE érkezik: közvetlenül a
             | küldés után kihirdetve mindig az nyerne, akinek a címzettjei épp a gépnél
             | ültek.
             */
            $table->string('winner_criterion')->nullable();
            $table->unsignedInteger('winner_after_minutes')->default(240);
            $table->timestampTz('winner_declared_at')->nullable();

            // A Horizon batch azonosítója (65. kritérium): ebből jön a progress.
            $table->string('batch_id')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            /*
             | A NÉV EGYEDISÉGE CSAK AZ ÉLŐ SOROKRA.
             |
             | Egy sima `unique` mellett a törölt sor neve VÉGLEGESEN foglalt marad: a
             | „Tavaszi hírlevél" nevet a tenant a törlés után sem tudná újra használni, és a
             | hibaüzenet egy olyan sorra hivatkozna, amit a felületen nem is lát. A parciális
             | index (`where deleted_at is null`) pontosan azt mondja ki, amit akarunk.
             */
            $table->index(['workspace_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });

        DB::statement('create unique index campaigns_workspace_name_unique on campaigns (workspace_id, name) where deleted_at is null');

        Schema::create('campaign_variants', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained()->nullOnDelete();

            $table->string('label');                       // „A", „B", …
            $table->string('subject')->nullable();
            // A FORGALOM aránya. Egész százalék: a felület ezt állítja, és az összegnek
            // 100-nak kell lennie — a maradékot nem osztjuk szét csendben.
            $table->unsignedInteger('weight')->default(50);

            $table->unsignedBigInteger('sent_count')->default(0);
            $table->unsignedBigInteger('opened_count')->default(0);
            $table->unsignedBigInteger('clicked_count')->default(0);

            // A GYŐZTES kihirdetése (64. kritérium): mikor, mi alapján.
            $table->boolean('is_winner')->default(false);
            $table->timestampTz('declared_at')->nullable();
            $table->string('decided_by')->nullable();      // open_rate | click_rate | manual

            $table->timestamps();

            $table->unique(['campaign_id', 'label']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_variant_id')->nullable()->constrained()->nullOnDelete();

            // A CÍM a küldés pillanatában. A kontakt később törölhető (GDPR), a kampány
            // riportja viszont nem menne szét ettől — a cím itt REDAKTÁLHATÓ (a
            // `logs:redact-subject` útján), a sor megmarad.
            $table->string('email');
            $table->string('email_hash', 64)->index();

            // `pending` | `sent` | `failed` | `skipped`
            $table->string('status')->default('pending');
            $table->string('skip_reason')->nullable();
            $table->uuid('message_uuid')->nullable();

            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();

            // A DEDUPLIKÁCIÓ: egy címzett egy kampányban EGYSZER. A lista ∪ szegmens unió
            // ugyanazt a címet több forrásból is adhatja — enélkül kétszer kapná meg.
            $table->unique(['campaign_id', 'email']);
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaign_variants');
        Schema::dropIfExists('campaigns');
    }
};
