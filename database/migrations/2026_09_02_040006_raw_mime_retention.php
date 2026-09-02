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

        // A MEGLÉVŐ sorok is kapnak lejáratot: enélkül a migráció előtt beadott levelek
        // örökre bennmaradnának — a trigger miatt pedig utólag már be sem lehetne állítani.
        DB::statement("update raw_mime_messages
            set expires_at = created_at + interval '30 days'
            where expires_at is null");

        // Az immutabilitás a TARTALOMRA vonatkozik. A retenciós határidő állítható marad:
        // a plan-függő tier (C1, ventPay `log_retention_tier`) különben alkalmazhatatlan
        // lenne a már beadott levelekre.
        DB::statement("create or replace function raw_mime_messages_immutable() returns trigger as \$\$
            begin
                -- Az `updated_at` is kivétel: az Eloquent minden mentésnél írja, tehát
                -- nélküle a retenciós határidő CSAK nyers query-vel lett volna állítható.
                if to_jsonb(new) - 'expires_at' - 'updated_at' = to_jsonb(old) - 'expires_at' - 'updated_at'
                   -- A határidő nem tolható a végtelenbe: a golden rule 10 kiskapuja lenne,
                   -- ha a retenciót egyetlen UPDATE-tel ki lehetne kapcsolni.
                   and new.expires_at is not null
                   and new.expires_at <= old.created_at + interval '400 days'
                then
                    return new;
                end if;

                raise exception 'A beadott nyers MIME nem irhato at: ez a kikuldott level maga (a lejarat legfeljebb 400 napra tolhato).';
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
