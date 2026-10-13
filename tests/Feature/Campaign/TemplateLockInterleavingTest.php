<?php

declare(strict_types=1);

use App\Modules\Template\Services\TemplateUsage;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | v1.1 · 5. modul, review SEC4-S1 / r5 N5 — VALÓDI zár-összefonódás KÉT adatbázis-kapcsolattal.
 |
 | A többi teszt szerkezeti (a zár-lekérdezés lefut). Itt egy MÁSODIK kapcsolat próbálja megszerezni
 | az ütköző zárat `NOWAIT`-tel: ha a mi zárunk él, a Postgres azonnal 55P03-at ad (nincs várakozás,
 | a teszt nem akadhat be). Ehhez COMMITOLT sorok kellenek — a `RefreshDatabase` tranzakciója a másik
 | kapcsolat elől elrejtené őket —, ezért ez a fájl szándékosan NEM használja a traitet: nyers sorokat
 | szúr be (modell-esemény és törölhetetlen audit-sor nélkül), és a végén kitörli őket.
 */

beforeEach(function () {
    // FELTÉTEL NÉLKÜLI, idempotens migrálás (review r6 S3): a trait nélküli fájl a séma bármely
    // állapotát (hiányzó tábla, hiányzó új oszlop) a futás elején rendbe teszi — `--parallel` alatt is
    // a saját teszt-adatbázisán. Ha nincs függő migráció, ez egy lekérdezés.
    $this->artisan('migrate', ['--force' => true]);

    config(['database.connections.lockprobe' => config('database.connections.'.config('database.default'))]);

    $this->workspaceId = DB::table('workspaces')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'name' => 'Zárteszt', 'slug' => 'zarteszt-'.Str::lower(Str::random(8)),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // A `TemplateUsage` a tenant-szűrt modellen zárol (a global scope a tenant-határ): munkakontextus kell,
    // ahogy minden éles úton (kérés, ütemező, journey-publikálás) van is.
    app(WorkspaceContext::class)->set(Workspace::query()->findOrFail($this->workspaceId));

    $this->templateId = DB::table('templates')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'workspace_id' => $this->workspaceId, 'name' => 'Zárteszt sablon',
        'marketing' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

afterEach(function () {
    DB::rollBack(0);
    app(WorkspaceContext::class)->forget();
    DB::connection('lockprobe')->disconnect();

    // NULL-BIZTOS takarítás (review r6 N2): ha a beforeEach félúton bukott, csak a létrejött sor törlődik.
    if (($this->templateId ?? null) !== null) {
        DB::table('templates')->where('id', $this->templateId)->delete();
    }

    if (($this->workspaceId ?? null) !== null) {
        DB::table('workspaces')->where('id', $this->workspaceId)->delete();
    }
});

/** A másik kapcsolat `NOWAIT`-tel próbál zárat szerezni — `true`, ha a zárunk blokkolná. */
function lockprobeBlocked(int $templateId, string $mode): bool
{
    try {
        DB::connection('lockprobe')->transaction(fn () => DB::connection('lockprobe')
            ->select("select id from templates where id = ? for {$mode} nowait", [$templateId]));

        return false;
    } catch (QueryException $e) {
        if ((string) ($e->errorInfo[0] ?? '') === '55P03') {
            return true;
        }

        throw $e;
    }
}

it('az élesítés (FOR SHARE) alatt a szerkesztő mentése (FOR UPDATE) NEM szerezhet zárat — és utána igen', function () {
    DB::beginTransaction();
    app(TemplateUsage::class)->lockForActivation([$this->templateId]);

    expect(lockprobeBlocked($this->templateId, 'update'))->toBeTrue()
        // Két élesítés (FOR SHARE) nem zárja ki egymást.
        ->and(lockprobeBlocked($this->templateId, 'share'))->toBeFalse();

    DB::rollBack();

    expect(lockprobeBlocked($this->templateId, 'update'))->toBeFalse();
});

it('a szerkesztő mentése (FOR UPDATE) alatt az élesítés (FOR SHARE) NEM szerezhet zárat', function () {
    DB::beginTransaction();
    DB::table('templates')->where('id', $this->templateId)->lockForUpdate()->first();

    expect(lockprobeBlocked($this->templateId, 'share'))->toBeTrue();

    DB::rollBack();
});
