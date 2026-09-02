<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Domains\Actions\CreateDomain;
use App\Modules\Domains\Actions\RotateDkimKey;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | M0-4 elfogadási kritérium #27 — rotáció ÁTFEDÉSSEL.
 |
 | Átfedés nélkül a rotáció pillanatában minden útban lévő levél aláírása érvénytelenné
 | válna, és a fogadó oldalak épp ilyenkor büntetnek a legkeményebben.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    $this->domain = app(CreateDomain::class)('pelda.hu');
});

it('a rotáció megőrzi az előző selectort és kulcsot', function () {
    $regiSelector = $this->domain->dkim_selector;
    $regiKulcs = $this->domain->dkim_private_key;

    app(RotateDkimKey::class)($this->domain);

    $friss = $this->domain->fresh();

    expect($friss->dkim_selector)->not->toBe($regiSelector);
    expect($friss->dkim_previous_selector)->toBe($regiSelector);
    expect($friss->dkim_previous_private_key)->toBe($regiKulcs);
    expect($friss->dkim_rotated_at)->not->toBeNull();
});

it('az előző kulcs az átfedés ideje alatt érvényes', function () {
    app(RotateDkimKey::class)($this->domain);

    expect($this->domain->fresh()->previousDkimIsValid())->toBeTrue();
});

it('az átfedés lejárta után az előző kulcs már nem érvényes', function () {
    app(RotateDkimKey::class)($this->domain);

    $this->domain->forceFill([
        'dkim_rotated_at' => now()->subDays((int) config('domains.dkim_rotation_overlap_days') + 1),
    ])->save();

    expect($this->domain->fresh()->previousDkimIsValid())->toBeFalse();
});

it('rotáció után a DKIM-státusz újra függőben van', function () {
    // Az új selector DNS-rekordja még nincs kint — a wizardnak ezt kell mutatnia.
    $this->domain->forceFill(['dkim_status' => 'verified', 'status' => 'verified'])->save();

    app(RotateDkimKey::class)($this->domain->fresh());

    expect($this->domain->fresh()->dkim_status)->toBe('pending');
});

it('az előző privát kulcs sem kerül soha válaszba', function () {
    app(RotateDkimKey::class)($this->domain);

    expect($this->domain->fresh()->toArray())->not->toHaveKey('dkim_previous_private_key');
});

it('a rotáció auditált, de a kulcsok nélkül', function () {
    app(RotateDkimKey::class)($this->domain);

    $log = AuditLog::query()->where('action', 'updated')->latest('id')->firstOrFail();
    $serialized = (string) json_encode([$log->before, $log->after]);

    expect($serialized)->not->toContain('PRIVATE KEY');
    // A rotáció TÉNYE viszont látszik.
    expect($log->after)->toHaveKey('dkim_selector');
});
