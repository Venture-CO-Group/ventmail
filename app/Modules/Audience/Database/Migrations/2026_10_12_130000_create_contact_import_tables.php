<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · Közönség-import — séma. EXPAND-ONLY: két ÚJ tábla, meglévőhöz nem nyúl.
 |
 | 1. `contact_fields` — az egyéni mezők DEFINÍCIÓJA (kulcs, címke, típus). A kontakt
 |    `attributes` JSON-ja továbbra is a tárolás; a definíció annyit ad hozzá, hogy a
 |    varázsló és a szegmens-szerkesztő tudja, MILYEN kulcsok léteznek és milyen típusúak.
 |
 | 2. `contact_imports` — egy import-köteg NYILVÁNTARTÁSA: ki, mikor, melyik fájl (név +
 |    hash), milyen jogalappal és forrással, milyen eredménnyel. Ez a GDPR-nyom a köteg
 |    szintjén; a kontakt szintjén a `consent_events` `imported` sora viszi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_fields', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('key', 40);
            $table->string('label', 120);
            // `text` | `number` | `date` | `boolean`
            $table->string('type', 16)->default('text');

            $table->timestamps();

            $table->unique(['workspace_id', 'key']);
        });

        Schema::create('contact_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // `manual` | `paste` | `csv` | `xlsx`
            $table->string('source_type', 16);
            $table->string('file_name')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            // A feltöltött fájl helye a PRIVÁT lemezen; a feldolgozás végén törlődik.
            $table->string('storage_path')->nullable();

            // A fájl felmérése (elválasztó, kódolás, fejléc, minta), a leképezés és a
            // beállítások. JSONB, mert a varázsló lépésenként tölti, és nem szegmentálunk rá.
            $table->jsonb('profile')->default('{}');
            $table->jsonb('mapping')->default('{}');

            $table->foreignId('contact_list_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('tags')->default('[]');
            $table->string('update_mode', 16)->default('skip');           // skip | fill | overwrite
            $table->string('legal_basis', 32)->nullable();
            $table->string('consent_source')->nullable();
            $table->date('consent_date')->nullable();
            $table->string('resulting_status', 16)->nullable();           // subscribed | pending
            $table->string('role_policy', 8)->default('skip');            // skip | import
            $table->string('disposable_policy', 8)->default('skip');      // skip | import

            // draft → previewing → previewed → queued → processing → completed | failed
            $table->string('status', 16)->default('draft');
            $table->string('phase', 24)->nullable();

            $table->unsignedBigInteger('total_rows')->default(0);
            $table->unsignedBigInteger('processed_rows')->default(0);
            $table->unsignedBigInteger('new_count')->default(0);
            $table->unsignedBigInteger('updated_count')->default(0);
            $table->unsignedBigInteger('skipped_count')->default(0);
            $table->unsignedBigInteger('error_count')->default(0);
            // Bontás (hibás, duplikátum, létező, suppression, szerepcím, eldobható) + minta.
            $table->jsonb('stats')->default('{}');

            $table->string('error_report_path')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'created_at']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('contact_imports');
        Schema::dropIfExists('contact_fields');
    }
};
