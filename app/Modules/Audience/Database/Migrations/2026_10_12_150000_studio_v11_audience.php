<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · Studio (5. modul) — közönség. EXPAND-ONLY: nullable / alapértékes oszlopok és új
 | indexek, meglévőhöz nem nyúl.
 |
 | 1. `contacts.source` — a kontakt FELIRATKOZÁSI forrása (`import` | `form` | `api` |
 |    `manual`). A közönség-dashboard forrás-bontása és a szegmens `source` mezője ebből
 |    dolgozik. NULL a v1.1 előtti soroknál: ott a marketing-consent `source`-a a tartalék
 |    (`AudienceInsights`/`SegmentEngine` ugyanazzal a kifejezéssel).
 | 2. Növekedés-indexek: a napi „új" és „leiratkozott" bontás a `(workspace_id, created_at)`
 |    és a részleges `(workspace_id, unsubscribed_at)` indexen fut, 300k kontaktnál is
 |    teljes tábla-olvasás nélkül.
 | 3. `forms.page` — a hosztolt űrlap-oldal és a köszönő oldal szövegei, a mezők
 |    sorrendje mellett (a `fields` már létezik).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('source', 16)->nullable();
            $table->index(['workspace_id', 'created_at'], 'contacts_ws_created_idx');
        });

        DB::statement('create index if not exists contacts_ws_unsubscribed_idx on contacts (workspace_id, unsubscribed_at) where unsubscribed_at is not null');

        Schema::table('forms', function (Blueprint $table): void {
            $table->jsonb('page')->default('{}');
        });
    }

    public function down(): void
    {
        DB::statement('drop index if exists contacts_ws_unsubscribed_idx');

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn('page');
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_ws_created_idx');
            $table->dropColumn('source');
        });
    }
};
