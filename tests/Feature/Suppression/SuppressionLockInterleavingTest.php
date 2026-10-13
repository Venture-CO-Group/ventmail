<?php

declare(strict_types=1);

use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Suppression\Services\SuppressionStore;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | v1.1 · 7. modul review 2 S1 (D198) — a suppression-felvétel VALÓDI zára, KÉT kapcsolattal.
 |
 | Ugyanaz a minta, mint a `TemplateLockInterleavingTest`: COMMITOLT sorok kellenek (a
 | `RefreshDatabase` tranzakciója a másik kapcsolat elől elrejtené őket), ezért a fájl NEM
 | használja a traitet — nyers sorokat szúr be, és a végén kitörli őket. A modell-eseményeket
 | (audit) a mérés idejére kikapcsoljuk: a megváltoztathatatlan audit-sort nem lehetne
 | takarítani, a zár viselkedését pedig nem érinti.
 */

beforeEach(function () {
    $this->artisan('migrate', ['--force' => true]);

    config(['database.connections.lockprobe' => config('database.connections.'.config('database.default'))]);

    $this->workspaceId = DB::table('workspaces')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'name' => 'Tiltás-zárteszt', 'slug' => 'tiltas-zar-'.Str::lower(Str::random(8)),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(WorkspaceContext::class)->set(Workspace::query()->findOrFail($this->workspaceId));
    $this->email = 'zar-'.Str::lower(Str::random(6)).'@example.test';
});

afterEach(function () {
    DB::rollBack(0);
    DB::connection('lockprobe')->rollBack(0);
    DB::connection('lockprobe')->disconnect();
    app(WorkspaceContext::class)->forget();

    if (($this->workspaceId ?? null) !== null) {
        DB::table('suppressions')->where('workspace_id', $this->workspaceId)->delete();
        DB::table('workspaces')->where('id', $this->workspaceId)->delete();
    }
});

function rawSuppression(int $workspaceId, string $email, string $reason, string $connection = 'pgsql'): void
{
    DB::connection($connection)->table('suppressions')->insert([
        'uuid' => (string) Str::uuid7(), 'workspace_id' => $workspaceId, 'email' => $email,
        'email_hash' => KeyedHash::of('email', $email), 'reason' => $reason, 'applies_to' => 'all',
        'source' => 'test', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('a felvétel a meglévő sort FOR UPDATE zárolja — a másik kapcsolat zárja alatt nem ír vakon', function () {
    rawSuppression($this->workspaceId, $this->email, 'unsubscribe');

    // A MÁSIK kapcsolat zárolja a sort (pl. egy párhuzamos one-click leiratkozás).
    DB::connection('lockprobe')->beginTransaction();
    DB::connection('lockprobe')->select('select id from suppressions where workspace_id = ? and email = ? for update', [$this->workspaceId, $this->email]);

    DB::statement("set lock_timeout = '300ms'");

    try {
        Model::withoutEvents(fn () => app(SuppressionStore::class)->addFromUi($this->email, 'blocked'));
        $blocked = false;
    } catch (QueryException $e) {
        $blocked = in_array((string) ($e->errorInfo[0] ?? ''), ['55P03', '57014'], true);
    } finally {
        DB::statement('set lock_timeout = 0');
        DB::connection('lockprobe')->rollBack();
    }

    expect($blocked)->toBeTrue();

    // A zár felengedése után a felvétel lefut — és a leiratkozás RAGADÓS marad (D186).
    $row = Model::withoutEvents(fn () => app(SuppressionStore::class)->addFromUi($this->email, 'blocked'));

    expect($row->reason)->toBe('unsubscribe');
});

it('ha a beszúrás ütközik (párhuzamos kérés nyert), nincs 500: a nyertes sorra fésül', function () {
    // A MÁSIK kapcsolat beszúrta és COMMITOLTA a sort — a mi beszúrásunk ütközne.
    rawSuppression($this->workspaceId, $this->email, 'unsubscribe', 'lockprobe');

    $store = app(SuppressionStore::class);

    // A közvetlen beszúrás ütközése null, nem kivétel (és a tranzakció használható marad).
    DB::transaction(function () use ($store): void {
        // Események NÉLKÜL nem lehet: a tenant-hook (workspace_id) is modell-esemény. Ütközésnél
        // a `created` (audit) úgysem fut le.
        expect(($store->createUnlessExists(['email' => $this->email, 'reason' => 'manual', 'applies_to' => 'all', 'source' => 'test'])))->toBeNull()
            ->and(DB::selectOne('select 1 as ok')->ok)->toBe(1);
    });

    $key = new ApiKey(['scopes' => ['suppressions:write']]);

    $row = Model::withoutEvents(fn () => $store->add($key, $this->email, 'bounce'));

    expect($row->reason)->toBe('unsubscribe')
        ->and(Suppression::query()->where('email', $this->email)->count())->toBe(1);
});
