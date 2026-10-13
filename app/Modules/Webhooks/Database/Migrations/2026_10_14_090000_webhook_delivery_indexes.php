<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | v1.1 · 7. modul review RS3 (D193) — a webhook-képernyő lekérdezéseinek indexei.
 | EXPAND-ONLY, `CONCURRENTLY` (a kézbesítési tábla nagy lehet).
 |
 | - `(workspace_id, created_at)`: a végpontonkénti 7 napos arány időablaka.
 | - `(webhook_endpoint_id, id)`: a végpontra szűrt napló (`order by id desc limit 100`).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::create('webhook_deliveries_workspace_created', 'on webhook_deliveries (workspace_id, created_at)');
        ConcurrentIndex::create('webhook_deliveries_endpoint_id', 'on webhook_deliveries (webhook_endpoint_id, id)');
    }

    public function down(): void
    {
        DB::statement('drop index concurrently if exists webhook_deliveries_endpoint_id');
        DB::statement('drop index concurrently if exists webhook_deliveries_workspace_created');
    }
};
