<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | C1 · A SZÁMLÁZÁSI ZÁRAK MEGERŐSÍTÉSE — a review mérései alapján.
 |
 | Az előző migráció azt állította, hogy „a trigger a VÉGSŐ HATÁR — ha valaha egy nyers
 | `UPDATE` mégis megpróbálná, az kivétel". A security-review ezt megmérte, és NEM volt
 | végső határ:
 |
 |   1) egy utasítás: `value = 0` lezárt soron              → BLOKKOLVA (ez működött)
 |   2) `closed_at := NULL`, majd `value := 0`, majd újra zárás → ÁTMENT
 |   3) EGY utasítás: `value = 0` ÉS `closed_at = NULL`     → ÁTMENT (nem kell két lépés)
 |   4) `DELETE` lezárt soron                               → ÁTMENT (a trigger csak UPDATE)
 |
 | És ott hiányzott teljesen, ahol a PÉNZ van — az `invoices` táblán nulla trigger volt:
 |
 |   5) `invoices.usage := 0` lezárt kivonaton              → ÁTMENT
 |   6) `dunning_state := 'paid'` (feloldás fizetés nélkül) → ÁTMENT
 |   7) `DELETE` a kivonaton                                → ÁTMENT
 |   8) `dunning_state := 'ingyen_kuldes'` (nem létező)     → ÁTMENT, és a kivonat VÉGLEG
 |      kiesett az állapotgépből (90 napos tartozás három `advance()` után is ott állt)
 |
 | HTTP-ből ma egyik sem elérhető (nincs Billing route), de mindhárom mező `$fillable`,
 | tehát az első kivonat-endpoint azonnal élesítené. A számlázás az a hely, ahol nem az
 | alkalmazás jóindulatára támaszkodunk.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | 1. A LEZÁRT FOGYÁS: az ÚJRANYITÁS is tilos, és a TÖRLÉS is.
         |
         | Az újranyitás (a `closed_at` nullázása) a C2 super-admin auditált művelete lesz —
         | addig NINCS rá út. Ez tudatos: egy „majd lesz auditálva" mező, amit ma bárki
         | átírhat, ma egy nyitott kapu. Amikor a C2 elkészül, ez a trigger egy
         | session-változóval feloldható lesz (`set local ventmail.reopen = 'on'`), amit
         | KIZÁRÓLAG az auditált parancs állít be.
         */
        DB::unprepared(<<<'SQL'
            create or replace function ventmail_usage_closed_guard() returns trigger as $$
            begin
                if old.closed_at is not null then
                    -- A LEZÁRÁS VISSZAVONÁSA: ezzel kerülte meg a régi trigger önmagát.
                    if new.closed_at is null then
                        raise exception
                            'A(z) % periódus fogyása LEZÁRVA (%): az újranyitás auditált művelet (C2)',
                            old.period, old.dimension
                            using errcode = 'check_violation';
                    end if;

                    if new.value is distinct from old.value then
                        raise exception
                            'A(z) % periódus fogyása LEZÁRVA (%): az érték nem módosítható (%→%)',
                            old.period, old.dimension, old.value, new.value
                            using errcode = 'check_violation';
                    end if;
                end if;

                return new;
            end;
            $$ language plpgsql;

            -- A TÖRLÉS is zárva: a régi trigger csak `before update` volt, tehát egy
            -- `DELETE` a teljes lezárt fogyást eltüntette volna.
            create or replace function ventmail_usage_closed_delete_guard() returns trigger as $$
            begin
                if old.closed_at is not null then
                    raise exception
                        'A(z) % periódus fogyása LEZÁRVA (%): a sor nem törölhető',
                        old.period, old.dimension
                        using errcode = 'check_violation';
                end if;

                return old;
            end;
            $$ language plpgsql;

            drop trigger if exists usage_records_closed_delete_guard on usage_records;

            create trigger usage_records_closed_delete_guard
                before delete on usage_records
                for each row execute function ventmail_usage_closed_delete_guard();
        SQL);

        /*
         | 2. A KIVONAT: a FOGYÁS-ADAT megváltoztathatatlan, a TÖRLÉS tilos, és az
         |    ÁLLAPOTGÉP csak érvényes értékeket vehet fel.
         |
         | Amit ENGEDÜNK: a `dunning_state` és az időbélyegek változását — az az
         | állapotgép munkája. Amit tiltunk: a `usage`, a `period` és a `workspace_id`
         | átírását, illetve a sor törlését. A kivonat TÖRTÉNELMI dokumentum: ha a
         | fogyás-adata utólag változhat, akkor nem dokumentum, hanem munkaterület.
         */
        DB::unprepared(<<<'SQL'
            create or replace function ventmail_invoice_guard() returns trigger as $$
            begin
                if new.usage is distinct from old.usage then
                    raise exception
                        'A(z) % periódus kivonata LEZÁRT dokumentum: a fogyás-adat nem módosítható',
                        old.period
                        using errcode = 'check_violation';
                end if;

                if new.period is distinct from old.period
                   or new.workspace_id is distinct from old.workspace_id
                then
                    raise exception
                        'A kivonat nem mozgatható át másik periódusba vagy tenantba (%)', old.id
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$ language plpgsql;

            drop trigger if exists invoices_guard on invoices;

            create trigger invoices_guard
                before update on invoices
                for each row execute function ventmail_invoice_guard();

            create or replace function ventmail_invoice_delete_guard() returns trigger as $$
            begin
                raise exception
                    'A kivonat nem törölhető (% / %): számviteli dokumentum',
                    old.period, old.id
                    using errcode = 'check_violation';
            end;
            $$ language plpgsql;

            drop trigger if exists invoices_delete_guard on invoices;

            create trigger invoices_delete_guard
                before delete on invoices
                for each row execute function ventmail_invoice_delete_guard();
        SQL);

        /*
         | 3. A DUNNING-ÁLLAPOT CHECK constraint.
         |
         | A review megmérte: egy `dunning_state = 'ingyen_kuldes'` érték ÁTMENT, és a
         | kivonat VÉGLEG kiesett az állapotgépből — egy 90 napos tartozás három
         | `advance()` után is ott állt, mert egyetlen `where` sem illeszkedett rá. Egy
         | elgépelés, egy migráció vagy egy kézi javítás így csendben ingyen küldést adna.
         */
        DB::statement(<<<'SQL'
            alter table invoices add constraint invoices_dunning_state_check
                check (dunning_state in ('open', 'due', 'overdue', 'suspended', 'paid'))
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table invoices drop constraint if exists invoices_dunning_state_check;
            drop trigger if exists invoices_delete_guard on invoices;
            drop function if exists ventmail_invoice_delete_guard();
            drop trigger if exists invoices_guard on invoices;
            drop function if exists ventmail_invoice_guard();
            drop trigger if exists usage_records_closed_delete_guard on usage_records;
            drop function if exists ventmail_usage_closed_delete_guard();
        SQL);
    }
};
