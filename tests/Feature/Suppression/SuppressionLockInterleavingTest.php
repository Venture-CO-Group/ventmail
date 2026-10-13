<?php

declare(strict_types=1);

use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Suppression\Services\SuppressionStore;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | v1.1 · 7. modul review 2 S1 (D198) — a suppression-felvétel VALÓDI zára, KÉT kapcsolattal.
 |
 | Ugyanaz a minta, mint a `TemplateLockInterleavingTest`: COMMITOLT sorok kellenek (a
 | `RefreshDatabase` tranzakciója a másik kapcsolat elől elrejtené őket), ezért a fájl NEM
 | használja a traitet. A fő kapcsolat egy visszagörgetett külső tranzakcióban dolgozik (az
 | audit-sor sem marad), a versenytárs sorait a másik kapcsolat commitolja, és a végén törlődnek.
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

    // A FŐ kapcsolat munkája egy KÜLSŐ tranzakcióban fut, amit a végén visszagörgetünk: így a
    // megváltoztathatatlan audit-sorok sem maradnak meg. A versenytárs sorait a MÁSIK
    // (autocommit) kapcsolat írja — azok commitoltak, és a takarítás törli őket.
    DB::beginTransaction();
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

function rawSuppression(int $workspaceId, string $email, string $reason, string $connection = 'lockprobe'): void
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
        app(SuppressionStore::class)->addFromUi($this->email, 'blocked');
        $blocked = false;
    } catch (QueryException $e) {
        $blocked = in_array((string) ($e->errorInfo[0] ?? ''), ['55P03', '57014'], true);
    } finally {
        DB::statement('set lock_timeout = 0');
        DB::connection('lockprobe')->rollBack();
    }

    expect($blocked)->toBeTrue();

    // A zár felengedése után a felvétel lefut — és a leiratkozás RAGADÓS marad (D186).
    $row = app(SuppressionStore::class)->addFromUi($this->email, 'blocked');

    expect($row->reason)->toBe('unsubscribe');
});

it('ha a beszúrás ütközik (párhuzamos kérés nyert a döntés ÉS a beszúrás között), nincs 500: a nyertes sorra fésül', function () {
    $store = app(SuppressionStore::class);

    // A horog a „nincs sor" döntés UTÁN, a beszúrás ELŐTT fut (D208): a MÁSIK kapcsolat ekkor
    // szúrja be és COMMITOLJA a leiratkozást — a mi beszúrásunk ütközik, és az újraolvasási
    // ág (FOR UPDATE + merged) fut le.
    $store->setBeforeInsertHook(fn (string $email) => rawSuppression($this->workspaceId, $email, 'unsubscribe', 'lockprobe'));

    $key = new ApiKey(['scopes' => ['suppressions:write']]);

    $row = $store->add($key, $this->email, 'bounce');

    expect($row->reason)->toBe('unsubscribe')
        ->and($row->workspace_id)->toBe($this->workspaceId)
        ->and(Suppression::query()->where('email', $this->email)->count())->toBe(1);
});

it('a törlés is FOR UPDATE zárol, és a közben beírt leiratkozást NEM törli (D207)', function () {
    rawSuppression($this->workspaceId, $this->email, 'manual');

    // A MÁSIK kapcsolat zárolja a sort, és leiratkozásra írja (one-click).
    DB::connection('lockprobe')->beginTransaction();
    DB::connection('lockprobe')->update('update suppressions set reason = ? where workspace_id = ? and email = ?', ['unsubscribe', $this->workspaceId, $this->email]);

    DB::statement("set lock_timeout = '300ms'");

    try {
        app(SuppressionStore::class)->removeWithJustification($this->email, 'Jegy #1', 'ui');
        $blocked = false;
    } catch (QueryException $e) {
        $blocked = in_array((string) ($e->errorInfo[0] ?? ''), ['55P03', '57014'], true);
    } finally {
        DB::statement('set lock_timeout = 0');
        DB::connection('lockprobe')->commit();
    }

    expect($blocked)->toBeTrue();

    // A zár után a törlés a FRISS (leiratkozás) állapotra dönt: 403-nak megfelelő kivétel.
    expect(fn () => app(SuppressionStore::class)->removeWithJustification($this->email, 'Jegy #1', 'ui'))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('suppressions')->where('workspace_id', $this->workspaceId)->where('email', $this->email)->value('reason'))->toBe('unsubscribe');
});
