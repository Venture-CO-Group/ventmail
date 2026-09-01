<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Domains\Models\Domain;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
 | M0-2 elfogadási kritérium #15 — minden admin-művelet auditált (golden rule 11).
 | Titok SOHA nem kerülhet az audit-sorba.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    $this->user = User::factory()->create();
    Auth::login($this->user);
});

it('a létrehozás audit-sort ír', function () {
    $domain = Domain::create(['name' => 'pelda.hu']);

    $log = AuditLog::query()->latest('id')->first();

    expect($log)->not->toBeNull();
    expect($log->action)->toBe('created');
    expect($log->auditable_type)->toBe(Domain::class);
    expect($log->auditable_id)->toBe($domain->id);
    expect($log->workspace_id)->toBe($this->workspace->id);
    expect($log->user_id)->toBe($this->user->id);
});

it('a módosítás rögzíti az előtte/utána állapotot', function () {
    $domain = Domain::create(['name' => 'pelda.hu']);
    $domain->update(['status' => 'verified']);

    $log = AuditLog::query()->where('action', 'updated')->latest('id')->firstOrFail();

    expect($log->before)->toMatchArray(['status' => 'pending']);
    expect($log->after)->toMatchArray(['status' => 'verified']);
});

it('a törlés is auditált', function () {
    $domain = Domain::create(['name' => 'pelda.hu']);
    $domain->delete();

    expect(AuditLog::query()->where('action', 'deleted')->exists())->toBeTrue();
});

it('titok SOHA nem kerül az audit-sorba', function () {
    $domain = Domain::create(['name' => 'pelda.hu']);
    $domain->update(['dkim_private_key' => 'SUPER-TITKOS-KULCS', 'dkim_selector' => 'vm1']);

    $log = AuditLog::query()->where('action', 'updated')->latest('id')->firstOrFail();

    $serialized = json_encode([$log->before, $log->after]);

    expect($serialized)->not->toContain('SUPER-TITKOS-KULCS');
    expect($log->after)->toHaveKey('dkim_selector');
    expect($log->after)->not->toHaveKey('dkim_private_key');
});

it('az audit-sor rögzíti a forrás IP hashét, nem a nyers IP-t', function () {
    Domain::create(['name' => 'pelda.hu']);

    $log = AuditLog::query()->latest('id')->firstOrFail();

    expect($log->ip_hash)->not->toBeEmpty();
    expect($log->ip_hash)->not->toContain('127.0.0.1');
});

it('az audit-napló nem módosítható és nem törölhető', function () {
    Domain::create(['name' => 'pelda.hu']);
    $log = AuditLog::query()->latest('id')->firstOrFail();

    expect(fn () => $log->update(['action' => 'hamisitott']))->toThrow(RuntimeException::class);
    expect(fn () => $log->delete())->toThrow(RuntimeException::class);
});
