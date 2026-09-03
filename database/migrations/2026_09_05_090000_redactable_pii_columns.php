<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | ÉRINTETTI TÖRLÉS (GDPR Art. 17) — a PII-oszlopok redaktálhatóvá tétele.
 |
 | A `message_events` már tudta ezt (`redacted_at` + trigger); az A3 három új PII-tárolója
 | nem: a `recipient` NOT NULL volt, tehát egy törlési kérésre nem lett volna mit tenni a
 | soron kívül, hogy TÖRÖLJÜK — a törlés viszont a kézbesítés tényét és a bounce-bizonyítékot
 | is elvinné.
 |
 | A NULL itt azt jelenti: „ebben a sorban már nincs személyes adat". Ez nem ugyanaz, mint a
 | hiányzó adat: a `redacted_at` mondja meg, hogy szándékos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smtp_transactions', function (Blueprint $table): void {
            $table->string('recipient')->nullable()->change();
            $table->timestampTz('redacted_at')->nullable();
        });

        Schema::table('delivery_attempts', function (Blueprint $table): void {
            $table->string('recipient')->nullable()->change();
            $table->timestampTz('redacted_at')->nullable();
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->timestampTz('redacted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('smtp_transactions', function (Blueprint $table): void {
            $table->dropColumn('redacted_at');
        });

        Schema::table('delivery_attempts', function (Blueprint $table): void {
            $table->dropColumn('redacted_at');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn('redacted_at');
        });
    }
};
