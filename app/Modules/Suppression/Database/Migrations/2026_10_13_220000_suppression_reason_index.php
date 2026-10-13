<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | v1.1 · 7. modul — a tiltólista KÜLÖN LISTÁI (bounce / panasz / leiratkozás / kézi) és
 | a fülenkénti darabszám okra szűr: `(workspace_id, reason, id)` index. EXPAND-ONLY,
 | `CONCURRENTLY` (a tábla milliós is lehet).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::create('suppressions_workspace_reason_id', 'on suppressions (workspace_id, reason, id)');
    }

    public function down(): void
    {
        DB::statement('drop index concurrently if exists suppressions_workspace_reason_id');
    }
};
