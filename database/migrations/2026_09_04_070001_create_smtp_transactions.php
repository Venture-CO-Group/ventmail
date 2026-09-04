<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Az SMTP-PÁRBESZÉD rögzítése (23–24. kritérium).
 |
 | Ez a „miért nem érkezett meg?" kérdés legfontosabb bizonyítéka: mit mondott a FOGADÓ.
 | Egy `550 5.1.1 User unknown` és egy `421 4.7.0 Try again later` két teljesen más
 | teendő — a különbség viszont csak akkor látszik, ha a válasz szövege is megmarad.
 |
 | PII: a párbeszéd címzett-címet és a fogadó szövegét tartalmazza (ami az eredeti tárgyat
 | is idézheti), ezért lejárati ideje van (golden rule 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('message_uuid')->index();
            $table->string('recipient');
            $table->foreignId('ip_address_id')->nullable()->constrained('ip_addresses')->nullOnDelete();

            $table->string('remote_host')->nullable();      // melyik MX-szel beszéltünk
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('enhanced_code', 16)->nullable();
            $table->text('response_text')->nullable();

            // A párbeszéd sorai: `[{"direction":"out","line":"MAIL FROM:<...>"} , ...]`.
            // A hitelesítési sorok SOHA nem kerülnek ide (a session redaktálja őket).
            $table->jsonb('lines')->default('[]');

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['workspace_id', 'message_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_transactions');
    }
};
