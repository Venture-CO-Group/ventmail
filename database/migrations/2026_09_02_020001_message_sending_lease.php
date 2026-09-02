<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | A `sending` állapot LÍZINGELT.
 |
 | Enélkül egy driver-kivétel (MTA timeout, process-halál) örökre `sending`-ben hagyta az
 | üzenetet, és minden újrapróbálkozás néma no-op volt: a levél soha nem ment ki, esemény
 | sem keletkezett róla. Egy e-mail-infrastruktúrában ez a legrosszabb hibamód.
 |
 | A lízing lejárta után az üzenet újraigényelhető — a feltételes UPDATE továbbra is
 | garantálja, hogy egyszerre csak egy futás küldje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('sending_started_at')->nullable()->after('queued_at');
            $table->unsignedSmallInteger('attempts')->default(0)->after('sending_started_at');
        });

        // A csatorna zárt halmaz: a szabad szöveg csatorna-hamisítást engedett
        // (ismeretlen érték a consent-kapun „nem marketing" címen ment át).
        DB::statement("alter table messages add constraint messages_channel_check
            check (channel in ('transactional', 'marketing', 'journey', 'campaign'))");
    }

    public function down(): void
    {
        DB::statement('alter table messages drop constraint if exists messages_channel_check');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['sending_started_at', 'attempts']);
        });
    }
};
