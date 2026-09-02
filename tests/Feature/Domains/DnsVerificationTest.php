<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Domains\Actions\CreateDomain;
use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Jobs\VerifyDomainDns;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Testing\FakeDnsResolver;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | M0-4 elfogadási kritérium #24 — a DNS-check állapotgépe.
 | A `verified → failed` visszaesés is kezelt: egy törölt DNS-rekord nem maradhat észrevétlen.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());

    $this->resolver = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->resolver);

    $this->domain = app(CreateDomain::class)('pelda.hu');
});

function publishAllRecords(FakeDnsResolver $resolver, Domain $domain): void
{
    foreach ($domain->dnsRecords() as $record) {
        $resolver->publish($record['name'], $record['type'], $record['value']);
    }
}

it('pending → verified, ha minden rekord a helyén van', function () {
    publishAllRecords($this->resolver, $this->domain);

    app(VerifyDomainDns::class)->handle($this->domain);

    $friss = $this->domain->fresh();

    expect($friss->status)->toBe('verified');
    expect($friss->spf_status)->toBe('verified');
    expect($friss->dkim_status)->toBe('verified');
    expect($friss->dmarc_status)->toBe('verified');
    expect($friss->verified_at)->not->toBeNull();
});

it('pending → failed, ha hiányzik egy rekord', function () {
    $records = $this->domain->dnsRecords();
    $this->resolver->publish($records['spf']['name'], 'TXT', $records['spf']['value']);

    app(VerifyDomainDns::class)->handle($this->domain);

    $friss = $this->domain->fresh();

    expect($friss->status)->toBe('failed');
    expect($friss->spf_status)->toBe('verified');
    expect($friss->dkim_status)->toBe('failed');
});

it('verified → failed, ha a rekord eltűnik', function () {
    publishAllRecords($this->resolver, $this->domain);
    app(VerifyDomainDns::class)->handle($this->domain);

    expect($this->domain->fresh()->status)->toBe('verified');

    // Az ügyfél törli a DKIM rekordot.
    $this->resolver->withdraw($this->domain->dnsRecords()['dkim']['name']);

    app(VerifyDomainDns::class)->handle($this->domain->fresh());

    expect($this->domain->fresh()->status)->toBe('failed');
    expect($this->domain->fresh()->verified_at)->toBeNull();
});

it('a részstátuszok nem hazudhatnak: a verified csak teljes egyezésre jár', function () {
    // Golden rule 8: SPF/DKIM/DMARC mind kell — a `verified` nem írható be részlegesen.
    $records = $this->domain->dnsRecords();
    $this->resolver->publish($records['spf']['name'], 'TXT', $records['spf']['value']);
    $this->resolver->publish($records['dkim']['name'], 'TXT', $records['dkim']['value']);
    $this->resolver->publish($records['dmarc']['name'], 'TXT', 'v=DMARC1; p=none; rua=mailto:masik@example.test');

    app(VerifyDomainDns::class)->handle($this->domain);

    expect($this->domain->fresh()->status)->toBe('failed');
});

it('az ellenőrzés rögzíti az utolsó futás idejét', function () {
    app(VerifyDomainDns::class)->handle($this->domain);

    expect($this->domain->fresh()->last_checked_at)->not->toBeNull();
});

it('az állapotváltás auditált', function () {
    publishAllRecords($this->resolver, $this->domain);
    app(VerifyDomainDns::class)->handle($this->domain);

    $log = AuditLog::query()->where('action', 'updated')->latest('id')->first();

    expect($log)->not->toBeNull();
    expect($log->after)->toHaveKey('status');
});
