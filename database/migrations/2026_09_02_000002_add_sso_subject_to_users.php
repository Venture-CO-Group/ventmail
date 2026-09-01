<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A users tábla a ventSSO-alany lokális tükre: a `sso_subject` a token `sub` claimje.
 | Ez az EGYETLEN kapocs az identitás-gazdához; jelszó itt sincs (golden rule 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_subject')->nullable()->unique()->after('id');
            $table->string('locale', 8)->default('hu')->after('email_verified_at');
            $table->timestamp('last_login_at')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sso_subject', 'locale', 'last_login_at']);
        });
    }
};
