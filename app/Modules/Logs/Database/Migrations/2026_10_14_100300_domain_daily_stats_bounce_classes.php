<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · 8. modul (D231) — BOUNCE-TÍPUSOK a domain-statisztikában. EXPAND-ONLY.
 |
 | Hat új számláló-oszlop, `default 0 not null`: a Postgres 11+ ezt metaadat-szinten veszi fel
 | (nincs táblaátírás), a régi kód nem írja őket (a default megmarad), tehát a kiszolgáló szín a
 | migráció után is működik. Visszamenőleges kitöltés NINCS: a grafikonon a régi napok bounce-a
 | „besorolatlan" (`bounced − Σ típus`).
 */
return new class extends Migration
{
    private const COLUMNS = ['bounced_hard', 'bounced_soft', 'bounced_mailbox_full', 'bounced_blocked', 'bounced_spam', 'bounced_policy'];

    public function up(): void
    {
        Schema::table('domain_daily_stats', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn('domain_daily_stats', $column)) {
                    $table->unsignedInteger($column)->default(0);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('domain_daily_stats', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
