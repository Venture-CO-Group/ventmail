<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · 5. modul, review r5 S2 — `campaigns.batch_generation`. EXPAND-ONLY (NOT NULL, default 0).
 |
 | A köteg-GENERÁCIÓ számlálója. A `batch_id` CAS-a nem elég: egy szüneteltetés a `batch_id`-t nem
 | változtatja, így egy szüneteltetés ELŐTT indult köteg-építés a folytatás után is „nyerhetett", a
 | folytatás saját kötege pedig törlődött — a szünet alatt korán kilépett jobok címzettjei örökre
 | `pending`-ben ragadtak. A szüneteltetés és a visszavonás a sorzár alatt lépteti a generációt; a
 | köteg csak a betöltéskor látott generációra íródik fel (és maga is léptet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->unsignedInteger('batch_generation')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('batch_generation');
        });
    }
};
