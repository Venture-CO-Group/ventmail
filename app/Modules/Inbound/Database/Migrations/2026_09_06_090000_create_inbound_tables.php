<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A4 · Inbound routing (MX) — séma.
 |
 | Három tábla, három külön élettartammal:
 |  - `inbound_routes`: a tenant KONFIGURÁCIÓJA, tartós;
 |  - `inbound_messages`: a beérkezett levél, RÖVID retencióval (spec: alap 3 nap) — nyers
 |    MIME idegen fájltárban, itt csak a kulcs és a metaadat;
 |  - `inbound_attachments`: csatolmány-metaadat, szintén kulccsal.
 |
 | A nyers levél SOHA nem a Postgresben ül: egy 25 MB-os `bytea` a naplózó lekérdezéseket is
 | megfojtaná, a retenciós törlés pedig táblányi holt helyet hagyna maga után.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_routes', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('description')->nullable();

            // A SORREND a szabály lelke: az első illeszkedő `stop()` zárja a láncot.
            // Alacsonyabb szám = előbb. Az azonos prioritásúak `id` szerint sorolódnak,
            // tehát a kiértékelés akkor is determinisztikus, ha az ügyfél nem ad prioritást.
            $table->integer('priority')->default(100);

            // match_recipient | match_header | catch_all
            $table->string('match_type');
            // `match_header` esetén a fejléc neve (`subject`), különben null.
            $table->string('match_field')->nullable();
            // Az ügyfél reguláris kifejezése. HOSSZ-LIMIT: egy 100 kB-os regex önmagában DoS.
            $table->string('expression', 512)->nullable();

            // [{"type":"forward","target":"https://…"}, {"type":"stop"}]
            $table->jsonb('actions')->default('[]');

            $table->boolean('active')->default(true);
            $table->timestampTz('last_matched_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'priority', 'id']);
        });

        Schema::create('inbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();

            $table->string('sender')->nullable();          // MAIL FROM (null sender = bounce)
            $table->string('recipient');                   // RCPT TO — a MI domainünk
            $table->string('subject')->nullable();
            $table->string('message_id')->nullable();

            // A nyers MIME a fájltárban. A `storage_key` az egyetlen hivatkozás.
            $table->string('storage_key')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->char('sha256', 64)->nullable();

            // received | parsed | routed | failed | quarantined
            $table->string('status')->default('received');
            $table->string('reason')->nullable();

            // A PARSE eredménye. A törzs itt ül, mert a route-motor és a `forward(url)`
            // payload is ebből dolgozik — a nyers MIME-hoz nem nyúlunk többször.
            $table->text('body_plain')->nullable();
            $table->text('body_html')->nullable();
            $table->text('stripped_text')->nullable();
            $table->jsonb('headers')->default('{}');

            // Hitelesítési eredmények és spam-pontszám: a `forward(url)` payload része,
            // és a fogadó ezek alapján dönt, hogy megbízik-e a levélben.
            $table->decimal('spam_score', 6, 2)->nullable();
            $table->string('spf_result')->nullable();
            $table->string('dkim_result')->nullable();
            $table->string('dmarc_result')->nullable();

            $table->timestampTz('received_at');
            $table->timestampTz('parsed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('redacted_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'received_at']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('inbound_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbound_message_id')->constrained()->cascadeOnDelete();

            $table->string('filename');
            $table->string('content_type');
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('storage_key')->nullable();

            // clean | infected | skipped — a fertőzött NEM tölthető le.
            $table->string('scan_result')->default('pending');
            $table->string('scan_detail')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'inbound_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_attachments');
        Schema::dropIfExists('inbound_messages');
        Schema::dropIfExists('inbound_routes');
    }
};
