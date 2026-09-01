<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Audit-napló (golden rule 11: minden admin-művelet auditált).
 |
 | A sor MEGVÁLTOZTATHATATLAN: sem update, sem delete (a modell őrzi). Titok soha nem
 | kerül bele; a forrás IP csak hashelve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action');                  // created|updated|deleted|<egyedi>
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->jsonb('before')->default('{}');
            $table->jsonb('after')->default('{}');
            $table->jsonb('context')->default('{}');

            $table->string('ip_hash', 64)->nullable(); // nyers IP SOHA
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
