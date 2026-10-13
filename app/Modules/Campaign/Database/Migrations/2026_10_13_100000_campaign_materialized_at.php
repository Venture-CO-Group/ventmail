<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · 5. modul, review SN2 — `campaigns.materialized_at`. EXPAND-ONLY (nullable oszlop).
 |
 | A címzett-halmaz TELJES felírásának pillanata. A `resume()` eddig a `recipients()->exists()`-ből
 | következtetett: egy félbehalt materializálás (az első kötegek beíródtak, a job meghalt) után a
 | folytatás a CSONKA közönségre küldött. NULL = a felírás nem fejeződött be → a folytatás újra
 | materializál (`insertOrIgnore`, idempotens).
 |
 | Visszatöltés: a már címzettel rendelkező (a v1.1 előtt materializált) kampányok értéket kapnak,
 | így a régi viselkedésük nem változik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->timestampTz('materialized_at')->nullable();
        });

        DB::table('campaigns')
            ->whereNull('materialized_at')
            ->where('recipient_count', '>', 0)
            ->update(['materialized_at' => DB::raw('coalesce(started_at, updated_at, now())')]);
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('materialized_at');
        });
    }
};
