<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Golden rule 10 (napló-retenció) és GDPR Art. 17 (törléshez való jog).
 |
 | A `message_events` PII-t hordoz (címzett, változók), a modell viszont minden UPDATE-et és
 | DELETE-et tiltott — így egy egyedi érintetti törlési kérést sem törléssel, sem redakcióval
 | nem lehetett teljesíteni, a `drop_chunks` pedig chunk-granularitású (nem lehet vele EGY
 | embert kivenni mindenki más 7 napja nélkül).
 |
 | Megoldás: engedélyezett REDAKCIÓS út — a PII-mezők nullázása + `redacted_at`. Minden más
 | UPDATE tiltva marad, tehát a napló továbbra sem írható át.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_events', function (Blueprint $table) {
            $table->timestampTz('redacted_at')->nullable();
        });

        DB::statement("create or replace function message_events_redaction_only() returns trigger as \$\$
            begin
                -- Csak a PII-mezők nullázása + redacted_at beállítása mehet át.
                if new.redacted_at is not null
                   and old.redacted_at is null
                   and new.recipient is null
                   -- Üres jsonb mindkét formában elfogadott: a PHP-oldali üres tömb `[]`-ként
                   -- szerializálódik, az üres objektum `{}`-ként.
                   and new.variables in ('{}'::jsonb, '[]'::jsonb)
                   and new.payload in ('{}'::jsonb, '[]'::jsonb)
                   and to_jsonb(new) - 'recipient' - 'variables' - 'payload' - 'redacted_at'
                       = to_jsonb(old) - 'recipient' - 'variables' - 'payload' - 'redacted_at'
                then
                    return new;
                end if;

                raise exception 'A message_events sor csak redakcioval modosithato (GDPR Art. 17), atirassal nem.';
            end;
        \$\$ language plpgsql");

        DB::statement('drop trigger if exists message_events_redaction_only_trigger on message_events');
        DB::statement('create trigger message_events_redaction_only_trigger
            before update on message_events
            for each row execute function message_events_redaction_only()');

        // Retenciós alapszabály: a nyers esemény lejáratkor törlődik, az aggregátum marad.
        // A plan-függő tier a C1 (ventPay `log_retention_tier`) feladata — ez a biztonságos
        // alapérték, hogy a retenció ne maradjon SEHOGY sem beállítva.
        DB::statement("select add_retention_policy('message_events', interval '90 days', if_not_exists => true)");
    }

    public function down(): void
    {
        DB::statement("select remove_retention_policy('message_events', if_exists => true)");
        DB::statement('drop trigger if exists message_events_redaction_only_trigger on message_events');
        DB::statement('drop function if exists message_events_redaction_only()');

        Schema::table('message_events', function (Blueprint $table) {
            $table->dropColumn('redacted_at');
        });
    }
};
