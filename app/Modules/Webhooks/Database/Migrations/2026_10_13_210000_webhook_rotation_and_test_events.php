<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · 7. modul — webhookok. EXPAND-ONLY.
 |
 | - `previous_secret` (titkosítva) + `previous_secret_expires_at`: titok-rotáció türelmi
 |   ablakkal (D171). Az ablak alatt a kézbesítés a régi titokkal is aláír (külön fejléc).
 | - `webhook_deliveries.is_test`: a felületről küldött teszt-esemény jelölése (D172) — nem
 |   valódi üzenetből származik, levél nem tartozik hozzá.
 | - `duration_ms`: a végpont válaszideje a kézbesítési naplóhoz.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('webhook_endpoints', 'previous_secret')) {
            Schema::table('webhook_endpoints', function (Blueprint $table): void {
                $table->text('previous_secret')->nullable();
                $table->timestampTz('previous_secret_expires_at')->nullable();
            });
        }

        if (! Schema::hasColumn('webhook_deliveries', 'is_test')) {
            Schema::table('webhook_deliveries', function (Blueprint $table): void {
                $table->boolean('is_test')->default(false);
                $table->unsignedInteger('duration_ms')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['is_test', 'duration_ms']);
        });

        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->dropColumn(['previous_secret', 'previous_secret_expires_at']);
        });
    }
};
