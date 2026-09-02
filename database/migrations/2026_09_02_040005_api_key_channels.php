<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A KULCS mondja meg, milyen csatornán küldhet.
 |
 | A `channel` eddig a hívó önbevallása volt: egy sima `messages:send` kulccsal marketing
 | tartalom ment ki `transactional` címkével — ekkor a ConsentGate azonnal átengedte, a
 | SuppressionGate pedig a marketing-leiratkozásokat NEM IS NÉZTE (a hatókör a csatornából
 | jön). A kérésbeli érték mostantól csak a kulcsnak ADOTT halmazon belül mozoghat.
 |
 | `NULL` = a konzervatív alapértelmezés (`transactional`), tehát a MEGLÉVŐ kulcsok nem
 | kapnak új jogot ettől a migrációtól.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->jsonb('channels')->nullable()->after('scopes');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', fn (Blueprint $table) => $table->dropColumn('channels'));
    }
};
