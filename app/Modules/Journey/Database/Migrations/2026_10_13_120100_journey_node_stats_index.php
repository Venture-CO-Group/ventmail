<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | v1.1 · 6. modul — a csomópont-statisztika „VÁR" oszlopa.
 |
 | A „hányan állnak most ezen a csomóponton" kérdés a FUTÓ futásokra szól. A részleges index
 | csak az `active` / `waiting` sorokat tartalmazza: a lezárt futások (a tábla döntő része)
 | nincsenek benne, így a csoportosítás nem olvassa végig a futás-táblát.
 |
 | `CONCURRENTLY`, tranzakció nélkül (a `contacts` indexeinek mintája): élesben a tick közben
 | a `journey_runs` folyamatosan íródik.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::create(
            'journey_runs_open_step_idx',
            "on journey_runs (journey_id, current_step_id) where status in ('active', 'waiting')",
        );
    }

    public function down(): void
    {
        DB::statement('drop index concurrently if exists journey_runs_open_step_idx');
    }
};
