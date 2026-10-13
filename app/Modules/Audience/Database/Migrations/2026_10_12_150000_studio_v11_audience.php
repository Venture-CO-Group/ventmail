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
    /**
     * Az indexek `CONCURRENTLY` épülnek (review NIT): egy 300k+ soros `contacts` táblán a sima
     * `CREATE INDEX` írás-zárat tart a teljes építés alatt — élesben a feliratkozások és az
     * importok erre várnának. A `CONCURRENTLY` tranzakción belül nem futhat, ezért a migráció
     * tranzakció nélküli; minden lépés idempotens (`if not exists`), egy félbeszakadt futás
     * újraindítható.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('contacts', 'source')) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->string('source', 16)->nullable();
            });
        }

        DB::statement('create index concurrently if not exists contacts_ws_created_idx on contacts (workspace_id, created_at)');
        DB::statement('create index concurrently if not exists contacts_ws_unsubscribed_idx on contacts (workspace_id, unsubscribed_at) where unsubscribed_at is not null');

        if (! Schema::hasColumn('forms', 'page')) {
            Schema::table('forms', function (Blueprint $table): void {
                $table->jsonb('page')->default('{}');
            });
        }
    }

    public function down(): void
    {
        DB::statement('drop index concurrently if exists contacts_ws_unsubscribed_idx');
        DB::statement('drop index concurrently if exists contacts_ws_created_idx');

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn('page');
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
