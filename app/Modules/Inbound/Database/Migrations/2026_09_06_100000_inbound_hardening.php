<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | A4 — séma-javítások a review után.
 |
 | 1. A `subject` és a `message_id` VARCHAR(255) volt. Egy 256 karakteres tárgy — egy
 |    hosszabb `Re: Fwd:` lánc teljesen szokványos — `SQLSTATE[22001]`-gyel elhasalt a
 |    mentésnél, az üzenet `PARSE_FAILED` lett, és a ROUTE-MOTOR EL SEM INDULT. Vagyis egy
 |    hosszú tárgysor csendben kikapcsolta a továbbítást a levelek egy nagy osztályára.
 |
 | 2. A verifikált inbound-domain neve GLOBÁLISAN egyedi. A `domains` unique kulcsa
 |    `(workspace_id, name)`, tehát ugyanaz a domain két tenantnál is `verified` lehetett —
 |    és a bejövő feloldás önkényesen az egyiket választotta volna. Az idegen tenanthoz
 |    kerülő levél a 6. golden rule sértése; a séma zárja ki, nem a kód figyelme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->text('subject')->nullable()->change();
            $table->text('message_id')->nullable()->change();
        });

        // Részleges unique index: CSAK a verifikált (és nem törölt) sorokra. A `pending`
        // állapotú igényből több is lehet — a versenyt a verifikáció dönti el.
        DB::statement("create unique index inbound_domain_unique
            on domains (name)
            where inbound_mx_status = 'verified' and deleted_at is null");
    }

    public function down(): void
    {
        DB::statement('drop index if exists inbound_domain_unique');

        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->string('subject')->nullable()->change();
            $table->string('message_id')->nullable()->change();
        });
    }
};
