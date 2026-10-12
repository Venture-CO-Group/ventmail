<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | `t:variables` — CSAK rendereléshez használt sablonváltozók (deploy-ovh, security-review B1).
 |
 | Titkosítva (az alkalmazás `encrypted:array` castja), és a küldés végén törlődik. A `v:*`
 | változókkal ellentétben SOHA nem kerül eseménybe, webhookba vagy API-válaszba.
 | Expand-only: egy új, nullable oszlop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->text('render_variables')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('render_variables');
        });
    }
};
