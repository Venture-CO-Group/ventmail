<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | B2 · A KÉT SZABÁLY AZ ADATBÁZISBAN IS ÁLL.
 |
 | A 61. („marketing sablon leiratkozó blokk nélkül nem mentődik") és a 62.
 | („`sending` kampány célzása és sablonja nem szerkeszthető") eddig CSAK az Eloquent
 | `save()` úton élt. Mérve mind ÁTMENT:
 |
 |   Template::query()->update(['marketing' => true])
 |   $template->forceFill(['marketing' => true])->saveQuietly()
 |   DB::table('templates')->update([...])
 |   a `sending` kampányon builder-`update()` / `saveQuietly()` átírta a `subject`-et
 |   és a `target_lists`-et
 |
 | Ma nincs más író, tehát nem kihasználható — de nincs semmi, ami ZÁRVA TARTSA. A repóban
 | már van precedens (az append-only táblák triggerei), és a tapasztalat ebben a projektben
 | többször ismétlődött: ami a fejlesztő emlékezetén múlik minden hívási helyen, azt
 | valahol elfelejtjük. Az adatbázis nem felejt.
 |
 | A trigger a MODELLT NEM VÁLTJA KI: a modell adja a beszédes hibaüzenetet a szerkesztőnek
 | („hiányzik a leiratkozó blokk, tedd be a levél aljára"), ez pedig a végső határ.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | A LEIRATKOZÓ BLOKK JELENLÉTE — `jsonb_path_exists`-szel, nem szövegkereséssel.
         |
         | A `blocks::text like '%unsubscribe%'` átengedne egy olyan sablont, amiben a szó
         | csak egy szövegblokk TARTALMÁBAN szerepel („Leiratkozás: unsubscribe"). A
         | `$.**.type ? (@ == "unsubscribe")` a fa BÁRMILYEN mélységében pontosan a
         | blokk-típust keresi — a `column`/`section` szint alatt is, ahol a blokk valójában
         | van.
         */
        DB::unprepared(<<<'SQL'
            create or replace function ventmail_templates_marketing_guard() returns trigger as $$
            begin
                if new.marketing is true
                   and not jsonb_path_exists(new.blocks, '$.**.type ? (@ == "unsubscribe")')
                then
                    raise exception
                        'A marketing sablonban KÖTELEZŐ a leiratkozó blokk (RFC 8058, golden rule 8): %',
                        new.id
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$ language plpgsql;

            create trigger templates_marketing_guard
                before insert or update on templates
                for each row execute function ventmail_templates_marketing_guard();
        SQL);

        /*
         | A KAMPÁNY-SZERKESZTÉS ZÁRA.
         |
         | A `sending`/`sent`/`canceled` kampány célzása, sablonja és tárgya NEM változhat: a
         | levél egy része már kiment, tehát a riport és a kiküldött tartalom szétcsúszna —
         | a felület egy tárgyat mutatna, a címzettek postafiókjában egy másik állna.
         |
         | Amit SZÁNDÉKOSAN engedünk: az állapot, a számlálók, a `batch_id`, az időpontok és
         | a győztes-mezők. Ezeket épp a küldés írja.
         */
        DB::unprepared(<<<'SQL'
            create or replace function ventmail_campaigns_edit_guard() returns trigger as $$
            begin
                if old.status in ('sending', 'sent', 'canceled') and (
                       new.subject is distinct from old.subject
                    or new.preheader is distinct from old.preheader
                    or new.template_id is distinct from old.template_id
                    or new.from_email is distinct from old.from_email
                    or new.from_name is distinct from old.from_name
                    or new.target_lists is distinct from old.target_lists
                    or new.target_segments is distinct from old.target_segments
                    or new.exclude_lists is distinct from old.exclude_lists
                    or new.exclude_segments is distinct from old.exclude_segments
                ) then
                    raise exception
                        'A(z) % állapotú kampány célzása, sablonja és tárgya nem szerkeszthető (kampány: %)',
                        old.status, old.id
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$ language plpgsql;

            create trigger campaigns_edit_guard
                before update on campaigns
                for each row execute function ventmail_campaigns_edit_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists templates_marketing_guard on templates;
            drop function if exists ventmail_templates_marketing_guard();
            drop trigger if exists campaigns_edit_guard on campaigns;
            drop function if exists ventmail_campaigns_edit_guard();
        SQL);
    }
};
