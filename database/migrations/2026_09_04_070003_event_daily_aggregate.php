<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | FOLYTONOS AGGREGÁTUM az eseményekre (25–26. kritérium).
 |
 | A retenció a NYERS eseményt törli — a statisztikát viszont nem szabad elvinnie. Ha a
 | tag-szintű arányokat futásidőben számolnánk a nyers sorokból, a 90. napon minden korábbi
 | kampány eredménye eltűnne. A Timescale continuous aggregate ezért ELŐRE anyagosítja a
 | napi bontást; a `drop_chunks` a nyers hypertable-t üríti, az aggregátum megmarad.
 |
 | A bontás: nap · workspace · csatorna · esemény-típus · tag. A tag jsonb-tömb, ezért
 | `jsonb_array_elements_text` bontja soronként — így a per-tag arány (delivered/open/
 | click/bounce) egyetlen indexelt lekérdezés.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("create materialized view message_event_daily
            with (timescaledb.continuous) as
            select
                time_bucket('1 day', occurred_at) as day,
                workspace_id,
                channel,
                type,
                coalesce(tag.value, '(nincs tag)') as tag,
                count(*) as total
            from message_events
            left join lateral jsonb_array_elements_text(
                case when jsonb_typeof(tags) = 'array' then tags else '[]'::jsonb end
            ) as tag(value) on true
            group by day, workspace_id, channel, type, tag.value
            with no data");

        // A frissítés az UTOLSÓ ÓRÁIG fut: a friss (még mozgó) ablakot a lekérdezés
        // valós időben egészíti ki (`materialized_only = false`).
        DB::statement('alter materialized view message_event_daily set (timescaledb.materialized_only = false)');

        DB::statement("select add_continuous_aggregate_policy('message_event_daily',
            start_offset => interval '30 days',
            end_offset => interval '1 hour',
            schedule_interval => interval '1 hour',
            if_not_exists => true)");
    }

    public function down(): void
    {
        DB::statement("select remove_continuous_aggregate_policy('message_event_daily', if_not_exists => true)");
        DB::statement('drop materialized view if exists message_event_daily');
    }
};
