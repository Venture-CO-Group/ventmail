<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | B3 · A JOURNEY-LÁNC INVARIÁNSAI AZ ADATBÁZISBAN.
 |
 | A `BelongsToWorkspace` őre a `workspace_id`-t nézi — a lánc-hivatkozásokat nem. Mérve
 | mind a kettő átment:
 |
 |   nyers UPDATE: a B tenant lépésének `next_step_id`-je A tenant lépésére (az FK nem tiltja)
 |   modellen keresztül: a B tenant `journey_run`-ja A tenant KONTAKTJÁRA (`contact_id`)
 |
 | A motor fail-closed, tehát adat nem szivárgott (az idegen lépést a `WorkspaceScope`
 | elrejti, az idegen sablon `template_missing`) — de a törött lánc korábban `completed`-ként,
 | `exit_reason = NULL`-lal zárult, vagyis a RIPORT SIKERNEK látta. És a B4 marketing
 | analitika épp ezekre a számlálókra épül.
 |
 | A precedens ugyanebben a repóban a `2026_09_12_092000_lock_template_and_campaign_invariants`:
 | ami a fejlesztő emlékezetén múlik minden hívási helyen, azt valahol elfelejtjük. Az
 | adatbázis nem felejt.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | 1. A LÁNC NEM LÉPHET ÁT sem tenant-, sem JOURNEY-határt.
         |
         | A `next_step_id` és az `else_step_id` ugyanahhoz a journey-hez tartozó lépésre
         | mutathat. A journey-határ azért is fontos, mert mérve a C journey lánca átmutatott
         | a B journey lépésére, és a C futása LEFUTTATTA B lépését: felkerült B címkéje, és
         | a `completed_count` a C-nél nőtt.
         */
        DB::unprepared(<<<'SQL'
            create or replace function ventmail_journey_step_chain_guard() returns trigger as $$
            declare
                cel record;
            begin
                for cel in
                    select 'next' as mezo, new.next_step_id as id
                    union all
                    select 'else' as mezo, new.else_step_id as id
                loop
                    if cel.id is null then
                        continue;
                    end if;

                    perform 1 from journey_steps s
                        where s.id = cel.id
                          and s.journey_id = new.journey_id
                          and s.workspace_id = new.workspace_id;

                    if not found then
                        raise exception
                            'A journey-lánc `%_step_id`-je IDEGEN journey vagy tenant lépésére mutat (lépés: %)',
                            cel.mezo, new.id
                            using errcode = 'check_violation';
                    end if;
                end loop;

                return new;
            end;
            $$ language plpgsql;

            create trigger journey_steps_chain_guard
                after insert or update on journey_steps
                for each row execute function ventmail_journey_step_chain_guard();
        SQL);

        /*
         | 2. A FUTÁS a SAJÁT tenant kontaktjához és a saját journey lépéséhez tartozik.
         |
         | A `contact_id` idegen tenant kontaktjára állítása mérve ÁTMENT a modellen: a
         | `BelongsToWorkspace` a sor `workspace_id`-jét ellenőrzi, a HIVATKOZOTT soréit nem.
         */
        DB::unprepared(<<<'SQL'
            create or replace function ventmail_journey_run_guard() returns trigger as $$
            begin
                if new.contact_id is not null then
                    perform 1 from contacts c
                        where c.id = new.contact_id and c.workspace_id = new.workspace_id;

                    if not found then
                        raise exception
                            'A journey-futás IDEGEN tenant kontaktjára mutat (futás: %)', new.id
                            using errcode = 'check_violation';
                    end if;
                end if;

                if new.current_step_id is not null then
                    perform 1 from journey_steps s
                        where s.id = new.current_step_id
                          and s.journey_id = new.journey_id
                          and s.workspace_id = new.workspace_id;

                    if not found then
                        raise exception
                            'A journey-futás IDEGEN journey vagy tenant lépésén áll (futás: %)', new.id
                            using errcode = 'check_violation';
                    end if;
                end if;

                return new;
            end;
            $$ language plpgsql;

            create trigger journey_runs_guard
                after insert or update on journey_runs
                for each row execute function ventmail_journey_run_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists journey_steps_chain_guard on journey_steps;
            drop function if exists ventmail_journey_step_chain_guard();
            drop trigger if exists journey_runs_guard on journey_runs;
            drop function if exists ventmail_journey_run_guard();
        SQL);
    }
};
