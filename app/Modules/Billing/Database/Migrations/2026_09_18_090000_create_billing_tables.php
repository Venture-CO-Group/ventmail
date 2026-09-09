<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | C1 · SZÁMLÁZÁS ÉS METERING — séma (75–80. kritérium).
 |
 | ## Amit a ventMail MÉR, és amit NEM tud
 |
 | A ventMail a FOGYÁST méri, nem a pénzt. Nincs itt ár, nincs adó, nincs fizetési mód, és
 | KÁRTYAADAT SOHA — az a ventPay dolga (golden rule: egy képesség, egy gazda). Amit a
 | `invoices` tábla tart, az egy ELSZÁMOLÁSI KIVONAT: mennyi fogyás keletkezett melyik
 | dimenzióban, melyik periódusban, melyik (sub-)accounton. A ventPay ebből számláz.
 |
 | Ez a különbségtétel nem szőrszálhasogatás: ha a ventMail árat tartana, két rendszer
 | mondaná meg, mennyibe kerül egy küldés — és a kettő elcsúszna. A `dunning_state` is
 | ezért csak TÜKÖR: a ventPay dönti el, hogy valaki fizetett-e, a ventMail csak azt
 | tudja, hogy emiatt küldhet-e.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | A FOGYÁS-SOROK.
         |
         | ## A GRAIN: (workspace, periódus, dimenzió) — és miért EZ
         |
         | Egy sor egy dimenzió egy periódusra. A küldés-számláló INKREMENTÁLIS (minden
         | levél +1), a `contacts_peak` MAXIMUM (a periódus csúcsa, nem a záró állapot — egy
         | importált, majd kitisztított lista után a záró szám kevesebb, de a rendszer
         | terhelését a csúcs adta), a `dedicated_ips` és a `log_retention_tier` pedig
         | PILLANATKÉP.
         |
         | Miért nem esemény-soronként: egy százezres kampány százezer fogyás-sort írna, és
         | a hónap végi összegzés a fél tábla pásztázása lenne. Az aggregált sor `upsert`-tel
         | nő, és az EGYEDI INDEX adja az idempotenciát — nem egy alkalmazás-szintű
         | „megnéztem, van-e" ellenőrzés, ami két párhuzamos küldés között befér.
         */
        Schema::create('usage_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // A PERIÓDUS `YYYY-MM` — a hónap az elszámolás egysége. Sztring, nem dátum: a
            // periódus KULCS, nem időpont, és a hónap határa a tenant időzónájától is
            // függhet (a zárás dönti el, mikor).
            $table->string('period', 7);

            // `sends_counter` | `contacts_peak` | `validations_counter` |
            // `inbound_counter` | `dedicated_ips` | `log_retention_tier`
            $table->string('dimension');

            /*
             | AZ ÉRTÉK, és miért `bigint`.
             |
             | A küldés-számláló egy nagy tenantnál havi tízmilliós is lehet; egy `integer`
             | (2,1 milliárd) elvileg elég, de a `bigint` költsége nulla, és a túlcsordulás
             | ára egy HAMIS SZÁMLA. Ahol a pénz a végén áll, ott nem spórolunk négy bájton.
             */
            $table->unsignedBigInteger('value')->default(0);

            /*
             | A LEZÁRÁS pillanata — és ez a 78. kritérium adatbázis-szintű zára.
             |
             | Lezárt periódus fogyása NEM írható. Nem azért, mert az alkalmazás nem próbál
             | (egy késve beérkező webhook-esemény bármikor megpróbálhatja), hanem mert a
             | trigger nem engedi. Enélkül egy már kiszámlázott hónap összege utólag
             | megváltozna, és a tenant számlája nem egyeznék a rendszerben látható számmal.
             */
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();

            // AZ IDEMPOTENCIA (80. kritérium): a kulcs az adatbázisban van.
            $table->unique(['workspace_id', 'period', 'dimension'], 'usage_records_unique');

            // A HAVI ZÁRÁS lekérdezése: periódus szerint, tenant-függetlenül (a parancs
            // workspace-enként állítja a kontextust, de a periódus a belépési pont).
            $table->index(['period', 'closed_at']);
        });

        /*
         | A KIVONAT (nem számla — lásd a fájl docblockját).
         |
         | Egy sor egy (workspace, periódus) párosra. A `parent_workspace_id` a
         | KONSZOLIDÁLÁS: a child kivonata a parenthez tartozik, de MEGMARAD önálló sorként
         | is — így a per-child bontás visszakereshető (77. kritérium), és a child fogyása
         | nem duplikálódik, mert a parent összesítője a child SORAIRA hivatkozik, nem
         | másolja őket.
         */
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // A SZÜLŐ, ha ez egy sub-account kivonata. `null` = önálló vagy parent.
            $table->foreignId('parent_workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();

            $table->string('period', 7);

            // A HAT DIMENZIÓ értéke a zárás pillanatában, jsonb-ben: a séma a dimenzió-
            // listával együtt változhat, és egy oszlop/dimenzió elrendezés minden új
            // dimenziónál migrációt kérne — a kivonat viszont TÖRTÉNELMI dokumentum.
            $table->jsonb('usage')->default('{}');

            /*
             | A DUNNING-ÁLLAPOT (79. kritérium): `open` → `due` → `overdue` → `suspended`,
             | és a rendezés `paid`.
             |
             | Ez TÜKÖR: a ventPay dönti el, hogy valaki fizetett-e. A ventMail csak azt
             | tudja, hogy emiatt küldhet-e — és a `suspended` a PreSendGate-en FOG, nem
             | csak a felületen látszik.
             */
            $table->string('dunning_state')->default('open');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();

            $table->timestampTz('closed_at');
            $table->timestamps();

            // EGY KIVONAT egy periódusra: a kétszeri zárás nem hoz létre másodikat
            // (78. kritérium).
            $table->unique(['workspace_id', 'period'], 'invoices_unique');
            $table->index(['dunning_state', 'due_at']);
        });

        /*
         | A LEZÁRT FOGYÁS ZÁRA (78. kritérium) — adatbázis-szinten.
         |
         | A precedens ugyanebben a repóban a B2 `templates_marketing_guard`-ja és a B3
         | `journey_steps_chain_guard`-ja: ami a fejlesztő emlékezetén múlik minden hívási
         | helyen, azt valahol elfelejtjük. Egy lezárt periódus fogyásának módosítása HAMIS
         | SZÁMLÁT jelentene — ez a legkevésbé az a hely, ahol az alkalmazás jóindulatára
         | támaszkodunk.
         |
         | Amit ENGEDÜNK: magát a lezárást (a `closed_at` beállítását) és a lezárás
         | VISSZAVONÁSÁT (a super-admin C2-ben újranyithat egy periódust — az auditált
         | művelet). Amit tiltunk: a lezárt sor ÉRTÉKÉNEK változtatását.
         */
        DB::unprepared(<<<'SQL'
            create or replace function ventmail_usage_closed_guard() returns trigger as $$
            begin
                if old.closed_at is not null
                   and new.closed_at is not null
                   and new.value is distinct from old.value
                then
                    raise exception
                        'A(z) % periódus fogyása LEZÁRVA (%): az érték nem módosítható (%→%)',
                        old.period, old.dimension, old.value, new.value
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$ language plpgsql;

            create trigger usage_records_closed_guard
                before update on usage_records
                for each row execute function ventmail_usage_closed_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists usage_records_closed_guard on usage_records;
            drop function if exists ventmail_usage_closed_guard();
        SQL);

        Schema::dropIfExists('invoices');
        Schema::dropIfExists('usage_records');
    }
};
