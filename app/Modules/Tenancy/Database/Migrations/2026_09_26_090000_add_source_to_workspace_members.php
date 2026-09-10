<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A TAGSÁG EREDETE.
 |
 | Miért kell: az al-fiókok tulajdonosi köre követi a parentét, és a visszavonásnál meg
 | KELL tudni különböztetni, hogy egy `owner` tagság a TÜKRÖZÉSBŐL származik-e, vagy a
 | childban külön osztották ki. A security-review pontosan ezen a különbségtételen kapott
 | el: az `array_diff(roles, ['owner'])` mindkettőt egyformán elvette, és egy parent-oldali
 | `admin` így törölni tudta a child SAJÁT tulajdonosát — pont az a jogosultsági inverzió,
 | amit két lépéssel korábban zártunk be.
 |
 | A jelölés SZŰK: csak a tükrözés írja, és csak a tükrözés veszi figyelembe. Ami `null`,
 | az emberi döntés — ahhoz a parent felől nem nyúlunk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->string('source', 40)->nullable()->after('roles');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
