<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | 1. API-kulcs: a `prefix` a lookup belépési pontja, ezért unique és indexelt.
 |    (A hash SHA-256 lesz, nem bcrypt — lásd ApiKey::issue. Egy nagy entrópiájú, gépi
 |    titoknál a bcrypt lassú ÉS kereshetetlen: a random só miatt minden kérésnél végig
 |    kellene próbálni az összes kulcsot.)
 |
 | 2. `consents` — a PreSendGate 3. kapuja (marketing csak érvényes, megerősített
 |    consenttel). A tábla itt születik, hogy az M0-3 gate ne stubbal induljon; a
 |    double opt-in FOLYAMAT gazdája a B1 packet (Consent modul).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('prefix', 24)->unique()->change();
            $table->boolean('testmode')->default(false)->after('name');
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('email');
            $table->string('email_hash', 64)->index();
            $table->string('channel')->default('marketing');   // marketing|transactional
            $table->string('status')->default('pending');      // pending|confirmed|revoked

            // Jogalap és bizonyíték (GDPR): honnan, mikor, milyen szöveggel.
            $table->string('legal_basis')->default('consent');
            $table->string('source')->nullable();              // form|import|api|preference_center
            $table->string('ip_hash', 64)->nullable();          // nyers IP SOHA
            $table->string('policy_version')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'email', 'channel']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');

        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('testmode');
        });
    }
};
