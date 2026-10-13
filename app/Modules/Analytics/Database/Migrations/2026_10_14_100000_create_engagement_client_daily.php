<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · 8. modul (D229) — ESZKÖZ- ÉS KLIENS-BONTÁS napi számlálója. ÚJ TÁBLA (expand-only).
 |
 | A nyitás/kattintás User-Agentjéből OSZTÁLYOZOTT kategóriák (eszköz, levelezőkliens) napi
 | ESEMÉNYszáma hatókörönként (`campaign:<uuid>` / `journey:<id>`). A nyers UA SOHA nem kerül
 | ide (sem máshova): csak a rögzített, nevesített kategória-kulcs.
 |
 | Az egyedi kulcs tartalmazza a `workspace_id`-t: az upsert INSERT, a global scope nem hat rá.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('engagement_client_daily')) {
            return;
        }

        Schema::create('engagement_client_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('scope');
            $table->date('day');
            $table->string('kind', 8);
            $table->string('device', 16);
            $table->string('client', 32);
            $table->unsignedInteger('total')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'scope', 'day', 'kind', 'device', 'client'], 'engagement_client_daily_unique');
            $table->index(['workspace_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_client_daily');
    }
};
