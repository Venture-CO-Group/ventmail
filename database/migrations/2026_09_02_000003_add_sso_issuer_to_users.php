<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Az OIDC `sub` csak KIBOCSÁTÓN BELÜL egyedi. Amíg a provider a lokális ventsso, majd
 | később az éles ventsso.app, a puszta `sub` ütközhetne: egy éles alany ráülhetne egy
 | dev-alany sorára. Az identitás kulcsa ezért (iss, sub).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_issuer')->after('id')->default('');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sso_subject']);
            $table->unique(['sso_issuer', 'sso_subject']);
        });

        // Nincs legacy sor: az „identitás = (iss, sub)" invariáns legyen DB-kényszer.
        DB::statement('alter table users alter column sso_subject set not null');
        DB::statement('alter table users alter column sso_issuer set not null');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sso_issuer', 'sso_subject']);
            $table->unique(['sso_subject']);
            $table->dropColumn('sso_issuer');
        });
    }
};
