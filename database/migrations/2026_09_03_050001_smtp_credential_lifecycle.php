<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | SMTP-hitelesítők életciklusa (ADR 0001, D6) — CSAK ADDITÍV.
 |
 | A `smtp_credentials` az M0-2 óta létezik; itt a hiányzó életciklus-mezők kerülnek rá:
 | lejárat, visszavonás, rotációs átfedés (az M0-4 DKIM-rotáció mintájára) és a csatorna
 | korlátozása. A `channel_restriction` alapértéke `transactional`: marketinget SMTP-n
 | consent-bizonyíték nélkül nem lehet küldeni (golden rule 4), a kivétel operátori döntés.
 |
 | A `last_ip_hash`: NYERS IP-t nem tárolunk (személyes adat) — kulcsolt hash megy, saját
 | domain-elválasztással.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smtp_credentials', function (Blueprint $table) {
            $table->string('name')->nullable()->after('domain_id');
            $table->string('channel_restriction')->default('transactional')->after('require_tls');
            $table->unsignedInteger('rate_limit_per_minute')->nullable()->after('channel_restriction');
            $table->timestampTz('expires_at')->nullable()->after('rate_limit_per_minute');
            $table->timestampTz('revoked_at')->nullable()->after('expires_at');
            $table->string('last_ip_hash', 64)->nullable()->after('last_used_at');
            $table->foreignId('rotated_from_id')->nullable()->after('revoked_at')
                ->constrained('smtp_credentials')->nullOnDelete();

            $table->index(['workspace_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('smtp_credentials', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'deleted_at']);
            $table->dropConstrainedForeignId('rotated_from_id');
            $table->dropColumn(['name', 'channel_restriction', 'rate_limit_per_minute', 'expires_at', 'revoked_at', 'last_ip_hash']);
        });
    }
};
