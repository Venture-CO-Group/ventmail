<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · 5. modul review-javítás. EXPAND-ONLY: két alapértékes logikai oszlop.
 |
 | - `contact_fields.preference_visible` (alapból HAMIS, B1): az egyéni mező csak akkor jelenik
 |   meg és írható a preference centerben, ha a tenant kifejezetten bekapcsolta. A /p/{token}
 |   link továbbítható, tehát a mező nem lehet alapból nyilvános.
 | - `forms.require_timing` (S1): a time-trap MÉRÉSE kötelező — az aláírt renderelési token
 |   (`_rt`) nélküli beküldés bot. A régi űrlapokon hamis (a már kint lévő beágyazott kódok
 |   `_elapsed`-del mérnek), az építőben létrehozott újakon alapból igaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_fields', function (Blueprint $table): void {
            $table->boolean('preference_visible')->default(false);
        });

        Schema::table('forms', function (Blueprint $table): void {
            $table->boolean('require_timing')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn('require_timing');
        });

        Schema::table('contact_fields', function (Blueprint $table): void {
            $table->dropColumn('preference_visible');
        });
    }
};
