<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Üzenetek (spec 8., 25.). A Mailgun-paritás vezérlői itt kapnak helyet, hogy az A1
 | Messages API csak a felületet tegye rá.
 |
 | `idempotency_key`: workspace-enként egyedi — kétszeri POST ugyanazzal a kulccsal
 | ugyanazt a választ adja, és NEM küld kétszer (golden rule 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ip_pool_id')->nullable()->constrained()->nullOnDelete();

            // transactional | marketing — nem keverhető (golden rule 4).
            $table->string('channel');

            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('subject')->nullable();
            $table->jsonb('recipients')->default('[]');

            $table->jsonb('tags')->default('[]');          // o:tag, max 3 (A1 kényszeríti)
            $table->jsonb('variables')->default('{}');     // v:* — visszajön az eseményben
            $table->jsonb('headers')->default('{}');       // h:X-*
            $table->jsonb('options')->default('{}');       // o:* egyéb vezérlők

            $table->timestamp('deliverytime')->nullable(); // o:deliverytime (ütemezés)
            $table->boolean('testmode')->default(false);   // o:testmode — naplóz, nem küld

            $table->string('status')->default('queued');   // queued|accepted|rejected|delivered|bounced
            $table->string('reason')->nullable();          // strukturált gate-elutasítási kód
            $table->string('idempotency_key')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
