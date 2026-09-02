<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A hitelesítés TÖRTÉNETE.
 |
 | A `verified_at` bizonyíték-értékű adat: mikortól küldhetett a tenant erről a domainről.
 | A visszaeséskor eddig kitörlődött; mostantól megmarad, és az `unverified_at` rögzíti,
 | mikor veszett el a hitelesítés. Az `dkim_active_selector` pedig azt mondja meg, MELYIK
 | kulccsal ír alá az aláíró (A2) — a rotáció alatt még a RÉGIVEL, amíg az új rekord
 | ténylegesen látszik a DNS-ben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->timestamp('unverified_at')->nullable()->after('verified_at');
            $table->string('dkim_active_selector')->nullable()->after('dkim_selector');
        });

        // A selector DNS-névként azonosít: tenantonként egyedinek kell lennie, különben az
        // új rekord ugyanarra a névre kerülne, mint a régi, és az átfedés némán elszakadna.
        Schema::table('domains', function (Blueprint $table) {
            $table->unique(['workspace_id', 'dkim_selector']);
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'dkim_selector']);
            $table->dropColumn(['unverified_at', 'dkim_active_selector']);
        });
    }
};
