<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | HONNAN SZÁRMAZIK A TULAJDONOSI JOG ezen a tagságon?
 |
 | Az al-fiókok tulajdonosi köre követi a parentét, és a visszavonásnál meg KELL tudni
 | különböztetni, hogy egy `owner` szerep a TÜKRÖZÉSBŐL származik-e, vagy a childban külön
 | osztották ki. A security-review pontosan ezen a különbségtételen kapott el kétszer:
 |
 |  1. jelölés NÉLKÜL a válogatás nélküli `array_diff(roles, ['owner'])` a child SAJÁT
 |     tulajdonosát is elvitte — egy parent-oldali `admin` jogán;
 |  2. SOR-szintű jelöléssel („ez a sor tükrözésből született") pedig mindkét irányban
 |     tévedett: aki a childban emberi döntésből `editor` volt, majd a parentben `owner`
 |     lett, JELÖLETLEN owner-t kapott — és a parentből való eltávolítása után is `owner`
 |     maradt a childban (fail-open, offboarding után teljes hozzáférés). Fordítva pedig a
 |     child saját tulajdonosának kiosztása egy tükrözött soron „tükrözöttnek" számított,
 |     és a parent leszedte.
 |
 | Ezért a jelölés SZEREP-SZINTŰ és boolean: kizárólag arról szól, hogy az `owner` szerepet
 | a tükrözés adta-e. Minden emberi kiosztás hamisra állítja — a döntés utolsó gazdája
 | számít, nem az, hogyan keletkezett a sor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->boolean('owner_mirrored')->default(false)->after('roles');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->dropColumn('owner_mirrored');
        });
    }
};
