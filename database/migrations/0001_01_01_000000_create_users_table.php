<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Golden rule 1: az identitás gazdája a ventSSO. A users tábla a ventSSO-alany
 | LOKÁLIS TÜKRE — jelszót, remember-tokent és jelszó-visszaállítást SOHA nem tárol.
 | A ventSSO `sub` claim leképezését az M0-1 (Identity modul) adja hozzá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Az azonosító a ventSSO `sub` (lásd a következő migrációt) — az e-mail csak
            // megjelenítési adat, ezért NEM unique: két SSO-alany címe egybeeshet, és az
            // e-mail alapú párosítás account takeover-t engedne.
            $table->string('email')->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
