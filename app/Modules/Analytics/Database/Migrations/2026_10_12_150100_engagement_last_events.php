<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · Studio — a „megnyitotta / kattintott az elmúlt N napban" szegmens-feltétel és a
 | közönség engagement-szintjei. EXPAND-ONLY.
 |
 | A `first_opened_at` az ELSŐ nyitás ideje (a riport mérőszáma); a „mikor volt utoljára
 | aktív?" kérdéshez a LEGUTOLSÓ kell. A régi sorokon NULL — a lekérdezések
 | `coalesce(last_opened_at, first_opened_at)`-tal olvasnak, tehát egy v1.1 előtti nyitás
 | sem tűnik el.
 |
 | Az index a kontakt → engagement kapcsolatot (`contacts.email_hash = recipient_hash`)
 | szolgálja: a meglévő egyedi index a `scope`-pal kezdődik, tehát címzettre nem kereshető.
 */
return new class extends Migration
{
    /** Az index `CONCURRENTLY` épül (a ténytábla kampányonként százezres) — tranzakción kívül. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('campaign_engagement', 'last_opened_at')) {
            Schema::table('campaign_engagement', function (Blueprint $table): void {
                $table->timestampTz('last_opened_at')->nullable();
                $table->timestampTz('last_clicked_at')->nullable();
            });
        }

        // Az INVALID maradványt a segéd eldobja és újraépíti (review NIT).
        ConcurrentIndex::create('campaign_engagement_recipient', 'on campaign_engagement (workspace_id, recipient_hash)');
    }

    public function down(): void
    {
        DB::statement('drop index concurrently if exists campaign_engagement_recipient');

        Schema::table('campaign_engagement', function (Blueprint $table): void {
            $table->dropColumn(['last_opened_at', 'last_clicked_at']);
        });
    }
};
