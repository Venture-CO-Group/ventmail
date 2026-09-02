<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | A nyers MIME RETENCIÓJA és megváltoztathatatlansága (golden rule 10, GDPR Art. 17).
 |
 | A `raw_mime_messages` a TELJES levéltestet tárolja, csatolmányostul: ez a rendszer
 | legsűrűbb PII-tárolója. Az előző migráció létrehozta, de retenció és törlési út nélkül —
 | márpedig a golden rule 10 nem halasztható azzal a lépéssel, amelyik a tárolót létrehozza.
 |
 | Két dolog kerül ide:
 | 1. `expires_at`: mikor törölhető a nyers levél (a plan-függő tier a C1 dolga; ez a
 |    biztonságos alapérték). A `messages:purge-raw-mime` parancs takarít.
 | 2. UPDATE-TRIGGER: a megváltoztathatatlanság eddig CSAK a modell-eventen ült, tehát egy
 |    `DB::table()->update()` átírta volna azt a levelet, ami már kiment. A sablon-verzióknál
 |    ugyanezt a review DB-triggerrel kérte — itt sem lehet gyengébb.
 |    A DELETE nyitva marad: az a RETENCIÓ útja (és az érintetti törlésé).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_mime_messages', function (Blueprint $table) {
            $table->timestampTz('expires_at')->nullable()->index();
        });

        DB::statement("create or replace function raw_mime_messages_immutable() returns trigger as \$\$
            begin
                raise exception 'A beadott nyers MIME nem irhato at: ez a kikuldott level maga.';
            end;
        \$\$ language plpgsql");

        DB::statement('drop trigger if exists raw_mime_messages_immutable_trigger on raw_mime_messages');
        DB::statement('create trigger raw_mime_messages_immutable_trigger
            before update on raw_mime_messages
            for each row execute function raw_mime_messages_immutable()');
    }

    public function down(): void
    {
        DB::statement('drop trigger if exists raw_mime_messages_immutable_trigger on raw_mime_messages');
        DB::statement('drop function if exists raw_mime_messages_immutable()');

        Schema::table('raw_mime_messages', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
