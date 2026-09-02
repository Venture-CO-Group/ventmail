<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Suppression\Services\SuppressionStore;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | M0-4 elfogadási kritérium #26 — suppression add/list globális ÉS workspace szinten,
 | `suppressions:write` scope-pal.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);
});

it('scope-pal fel lehet venni suppressiont', function () {
    ['model' => $key] = ApiKey::issue('CI', ['suppressions:write']);

    $suppression = app(SuppressionStore::class)->add($key, 'tiltott@example.test', 'manual');

    expect($suppression->email)->toBe('tiltott@example.test');
    expect($suppression->workspace_id)->toBe($this->workspace->id);
});

it('scope nélkül nem lehet', function () {
    ['model' => $key] = ApiKey::issue('CI', ['messages:send']);

    expect(fn () => app(SuppressionStore::class)->add($key, 'tiltott@example.test', 'manual'))
        ->toThrow(AuthorizationException::class);
});

it('a lista a saját ÉS a globális bejegyzéseket adja', function () {
    ['model' => $key] = ApiKey::issue('CI', ['suppressions:read', 'suppressions:write']);

    app(SuppressionStore::class)->add($key, 'sajat@example.test', 'bounce');
    Suppression::onPlatform(fn () => Suppression::create(['email' => 'globalis@example.test', 'reason' => 'policy']));

    $lista = app(SuppressionStore::class)->list($key);

    expect($lista->pluck('email')->all())->toEqualCanonicalizing(['sajat@example.test', 'globalis@example.test']);
});

it('a másik tenant bejegyzése nem látszik', function () {
    ['model' => $key] = ApiKey::issue('CI', ['suppressions:read', 'suppressions:write']);
    app(SuppressionStore::class)->add($key, 'sajat@example.test', 'bounce');

    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);
    ['model' => $masikKulcs] = ApiKey::issue('Másik', ['suppressions:read']);

    expect(app(SuppressionStore::class)->list($masikKulcs)->pluck('email')->all())->toBe([]);
});

it('ismételt felvétel nem duplikál', function () {
    ['model' => $key] = ApiKey::issue('CI', ['suppressions:write']);

    app(SuppressionStore::class)->add($key, 'tiltott@example.test', 'bounce');
    app(SuppressionStore::class)->add($key, 'TILTOTT@example.test', 'complaint');

    expect(Suppression::count())->toBe(1);
    // A LEGSÚLYOSABB ok marad meg: a panasz erősebb jelzés, mint a bounce.
    expect(Suppression::first()->reason)->toBe('complaint');
});

it('a felvétel auditált, nyers cím nélkül', function () {
    ['model' => $key] = ApiKey::issue('CI', ['suppressions:write']);

    app(SuppressionStore::class)->add($key, 'tiltott@example.test', 'manual');

    $log = AuditLog::query()
        ->where('auditable_type', Suppression::class)->latest('id')->firstOrFail();

    expect((string) json_encode([$log->before, $log->after]))->not->toContain('tiltott@example.test');
});
