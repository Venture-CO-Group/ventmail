<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | FO1 — a tárolt sablonverzió saját TÁRGYA (`{{ var }}` helyőrzőkkel).
 |
 | Expand-only: egy új, nullable oszlop. A megváltoztathatatlansági trigger
 | (`stored_template_versions_immutable`) a teljes sort összeveti, tehát az új oszlop is
 | védett: a tárgy utólag sem írható át, csak új verzióval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stored_template_versions', function (Blueprint $table) {
            $table->string('subject', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stored_template_versions', function (Blueprint $table) {
            $table->dropColumn('subject');
        });
    }
};
