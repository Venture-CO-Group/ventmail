<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | B2 · Block editor — séma (58–61., 67. kritérium).
 |
 | A SABLON EGYETLEN IGAZSÁGA A BLOCK-TREE. A HTML és a plaintext ebből SZÁRMAZIK, és
 | gyorsítótárazva tárolódik — nem szerkeszthető külön. Ez tudatos:
 |
 |  - a block-tree a ventStudio-val KÖZÖS JSON Schema (a `.claude/rules` szerint a séma
 |    közös, az MJML-fordítás a ventMail oldalán van), tehát ez az alak az, amit két app is
 |    ért. Ha a HTML lenne az igazság, a szerkesztő nem tudná visszaolvasni;
 |  - a HTML-t a fordítónk állítja elő. Ha kézzel is szerkeszthető lenne, a következő
 |    fordítás felülírná — vagy nem, és akkor két igazság élne egymás mellett.
 |
 | A `rendered_at` és a `render_hash` a gyorsítótár érvényességét jelzi: a fordító
 | verziójának változásakor a HTML újraépül, és a snapshot-teszt (59. kritérium) pontosan
 | ezt a determinizmust őrzi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('subject')->nullable();
            $table->string('preheader')->nullable();

            /*
             | A KÖZÖS block-tree (58. kritérium). JSON Schema ellen validál, és a séma a
             | ventStudio-val közös — ezért van itt, és nem MJML-ként vagy HTML-ként.
             */
            $table->jsonb('blocks')->default('[]');

            /*
             | MARKETING-e a sablon?
             |
             | Ez dönti el, hogy a LEIRATKOZÓ BLOKK kötelező-e (61. kritérium). Nem a
             | kampány dönti el, hanem a sablon: ugyanaz a sablon nem használható egyszer
             | leiratkozó blokkal, egyszer nélküle — a második használat pont az lenne, amit
             | a szabály tilt.
             */
            $table->boolean('marketing')->default(true);

            // A FORDÍTÁS gyorsítótára. Az igazság a `blocks`; ez csak gyorsítás.
            $table->text('mjml')->nullable();
            $table->text('html')->nullable();
            $table->text('text')->nullable();
            $table->string('render_hash', 64)->nullable();
            $table->timestampTz('rendered_at')->nullable();

            // A publikálás nyoma (67. kritérium): melyik tárolt sablonná és mikor.
            $table->foreignId('stored_template_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('published_at')->nullable();

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
        });

        DB::statement('create unique index templates_workspace_name_unique on templates (workspace_id, name) where deleted_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
