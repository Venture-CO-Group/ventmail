<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 | A megváltoztathatatlanság és a platform-határ nem állhat pusztán modell-eventeken:
 | a builder-út (Model::query()->update()) NEM vált ki modell-eventet.
 */

beforeEach(function () {
    $this->a = Workspace::factory()->create();
    $this->b = Workspace::factory()->create();
});

it('a tenant nem módosíthatja a platform-szintű suppressiont', function () {
    Suppression::onPlatform(fn () => Suppression::create(['email' => 'globalis@example.test', 'reason' => 'policy']));

    app(WorkspaceContext::class)->set($this->b);
    $row = Suppression::where('email', 'globalis@example.test')->firstOrFail();

    expect(fn () => $row->update(['reason' => 'HAMISITOTT']))->toThrow(RuntimeException::class);
});

it('a tenant nem törölheti a platform-szintű suppressiont', function () {
    // Ha törölhetné, a leiratkozott címre onnantól MINDEN tenant küldhetne.
    Suppression::onPlatform(fn () => Suppression::create(['email' => 'globalis@example.test', 'reason' => 'complaint']));

    app(WorkspaceContext::class)->set($this->b);
    $row = Suppression::where('email', 'globalis@example.test')->firstOrFail();

    expect(fn () => $row->delete())->toThrow(RuntimeException::class);
    expect(Suppression::count())->toBe(1);
});

it('a saját suppression törölhető', function () {
    app(WorkspaceContext::class)->set($this->a);
    $row = Suppression::create(['email' => 'sajat@example.test', 'reason' => 'manual']);

    $row->delete();

    expect(Suppression::count())->toBe(0);
});

it('az audit-napló a builder-úton sem módosítható', function () {
    app(WorkspaceContext::class)->set($this->a);
    $log = app(AuditLogger::class)->log('teszt.muvelet');

    // A DB-trigger dob; utána a tranzakció abortált állapotban van, ezért ebben a tesztben
    // már nem kérdezhetünk le — a lényeg, hogy az írás NEM megy át.
    expect(fn () => AuditLog::query()->whereKey($log->id)->update(['action' => 'HAMISITOTT']))
        ->toThrow(QueryException::class);
});

it('az audit-napló a builder-úton sem törölhető', function () {
    app(WorkspaceContext::class)->set($this->a);
    $log = app(AuditLogger::class)->log('teszt.muvelet');

    expect(fn () => AuditLog::query()->whereKey($log->id)->delete())->toThrow(QueryException::class);
});

it('az esemény-napló sora nem törölhető modellen át', function () {
    app(WorkspaceContext::class)->set($this->a);
    $event = MessageEvent::create(['type' => 'complained', 'recipient' => 'panasz@example.test']);

    expect(fn () => $event->delete())->toThrow(RuntimeException::class);
});

it('a globális suppression nem duplikálódik', function () {
    // Postgresben a unique(workspace_id, email) NEM korlátozza a NULL workspace_id sorokat.
    Suppression::onPlatform(fn () => Suppression::create(['email' => 'ismetelt@example.test', 'reason' => 'complaint']));

    expect(fn () => Suppression::onPlatform(fn () => Suppression::create([
        'email' => 'ismetelt@example.test',
        'reason' => 'complaint',
    ])))->toThrow(QueryException::class);
});

it('a workspace törölhető marad audit-sorok mellett is', function () {
    // A `SET NULL` FK maga is UPDATE a hivatkozó táblán — az immutabilitási trigger
    // enélkül LEHETETLENNÉ tenné a workspace és a user törlését, azaz a GDPR Art. 17
    // törlési utat.
    app(WorkspaceContext::class)->set($this->a);
    app(AuditLogger::class)->log('teszt.muvelet');

    $this->a->forceDelete();

    $log = AuditLog::query()->latest('id')->firstOrFail();

    expect($log->workspace_id)->toBeNull();
    // Az aktor-pillanatkép miatt a nyom megmarad.
    expect($log->action)->toBe('teszt.muvelet');
});

it('a platform-sor a builder-úton sem módosítható tenant-kontextusból', function () {
    // A modell-event nem fut le Model::query()->update() esetén — DB-szintű őr kell.
    Suppression::onPlatform(fn () => Suppression::create(['email' => 'globalis@example.test', 'reason' => 'policy']));

    app(WorkspaceContext::class)->set($this->b);

    expect(fn () => Suppression::query()->whereNull('workspace_id')->update(['reason' => 'ATIRVA']))
        ->toThrow(QueryException::class);
});

it('a platform-sor a builder-úton sem törölhető tenant-kontextusból', function () {
    Suppression::onPlatform(fn () => Suppression::create(['email' => 'globalis@example.test', 'reason' => 'complaint']));

    app(WorkspaceContext::class)->set($this->b);

    expect(fn () => Suppression::query()->whereNull('workspace_id')->delete())
        ->toThrow(QueryException::class);
});

it('az audit-napló TRUNCATE-tel sem üríthető', function () {
    app(WorkspaceContext::class)->set($this->a);
    app(AuditLogger::class)->log('teszt.muvelet');

    expect(fn () => DB::statement('truncate audit_logs'))->toThrow(QueryException::class);
});
