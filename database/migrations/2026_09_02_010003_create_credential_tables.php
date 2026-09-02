<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | API-kulcsok és SMTP-hitelesítők (spec 24.).
 | Nyers titok SOHA nem kerül az adatbázisba: csak hash. A kulcs egyetlen egyszer,
 | a létrehozáskor látszik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 16);            // megjelenítéshez: vm_live_ab12…
            $table->string('key_hash')->unique();    // SOSEM a nyers kulcs
            $table->jsonb('scopes')->default('[]');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('smtp_credentials', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->string('username')->unique();
            $table->string('password_hash');         // SOSEM a nyers jelszó
            $table->boolean('require_tls')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_credentials');
        Schema::dropIfExists('api_keys');
    }
};
