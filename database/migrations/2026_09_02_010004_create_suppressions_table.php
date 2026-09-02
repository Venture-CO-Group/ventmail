<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Suppression (spec 6.2 — a PreSendGate 2. kapuja).
 | GDPR-törlés után is MEGMARAD: a leiratkozás tényét nem szabad elfelejteni.
 | A `workspace_id` nullable: a NULL a GLOBÁLIS (minden tenantra érvényes) tiltás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('email_hash', 64)->index();   // kereséshez PII-mentesen
            $table->string('reason');                     // bounce|complaint|unsubscribe|manual|policy
            $table->string('source')->nullable();
            $table->text('detail')->nullable();
            $table->timestamp('expires_at')->nullable();  // pl. soft bounce ideiglenes tiltása
            $table->timestamps();

            $table->unique(['workspace_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};
