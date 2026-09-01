<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Küldő domainek és feladó-identitások (spec 4.).
 | „Deliverability nem alku": verifikálatlan domainről nincs küldés — a státusz-mezőket a
 | PreSendGate 1. kapuja olvassa (M0-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('pending');          // pending|verified|failed

            // Hitelesítési rekordok állapota
            $table->string('spf_status')->default('pending');
            $table->string('dkim_status')->default('pending');
            $table->string('dmarc_status')->default('pending');
            $table->string('ptr_status')->default('pending');
            $table->string('mta_sts_status')->default('disabled');
            $table->string('bimi_status')->default('disabled');

            // DKIM kulcspár — a PRIVÁT kulcs titkosítva tárolódik és sosem kerül válaszba.
            $table->string('dkim_selector')->nullable();
            $table->text('dkim_public_key')->nullable();
            $table->text('dkim_private_key')->nullable();
            $table->string('dkim_previous_selector')->nullable();   // rotáció átfedéses ideje
            $table->timestamp('dkim_rotated_at')->nullable();

            // Klikk-átirányítás és nyitás-pixel domainenként
            $table->string('tracking_domain')->nullable();
            $table->string('tracking_status')->default('pending');

            // Bejövő irány (A4): MX-rekord ellenőrzése
            $table->string('inbound_mx_status')->default('disabled');

            $table->boolean('tracking_enabled')->default(true);
            $table->boolean('require_tls')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('sending_identities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['workspace_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sending_identities');
        Schema::dropIfExists('domains');
    }
};
