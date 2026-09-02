<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Domains\Actions\CreateDomain;
use App\Modules\Domains\Models\Domain;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | M0-4 elfogadási kritérium #23 — a wizard generálja a hitelesítéshez szükséges rekordokat.
 | „Deliverability nem alku" (golden rule 8): verifikálatlan domainről nincs küldés.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
});

it('a wizard minden szükséges DNS-rekordot generál', function () {
    $domain = app(CreateDomain::class)('pelda.hu');

    $records = $domain->dnsRecords();

    expect($records)->toHaveKeys(['spf', 'dkim', 'dmarc', 'tracking']);

    expect($records['spf']['type'])->toBe('TXT');
    expect($records['spf']['value'])->toContain('v=spf1')->toContain('include:');

    expect($records['dkim']['type'])->toBe('TXT');
    expect($records['dkim']['name'])->toContain('._domainkey.pelda.hu');
    expect($records['dkim']['value'])->toStartWith('v=DKIM1; k=rsa; p=');

    expect($records['dmarc']['name'])->toBe('_dmarc.pelda.hu');
    expect($records['dmarc']['value'])->toContain('v=DMARC1');

    expect($records['tracking']['type'])->toBe('CNAME');
    expect($records['tracking']['name'])->toContain('pelda.hu');
});

it('a DKIM kulcspár valódi és a privát kulcs sosem kerül válaszba', function () {
    $domain = app(CreateDomain::class)('pelda.hu');

    expect($domain->dkim_selector)->not->toBeEmpty();
    expect($domain->dkim_private_key)->toContain('PRIVATE KEY');
    expect(openssl_pkey_get_private($domain->dkim_private_key))->not->toBeFalse();

    // A publikus kulcs a privátból származik — nem véletlen sztring.
    $details = openssl_pkey_get_details(openssl_pkey_get_private($domain->dkim_private_key));
    expect($domain->dkim_public_key)->toBe(
        str_replace(["-----BEGIN PUBLIC KEY-----\n", "-----END PUBLIC KEY-----\n", "\n"], '', $details['key'])
    );

    // Szerializálva SOHA nem megy ki.
    expect($domain->toArray())->not->toHaveKey('dkim_private_key');
    expect(json_encode($domain->dnsRecords()))->not->toContain('PRIVATE KEY');
});

it('a domain kezdetben függőben van, és nem küldhet', function () {
    $domain = app(CreateDomain::class)('pelda.hu');

    expect($domain->status)->toBe('pending');
    expect($domain->isVerified())->toBeFalse();
});

it('a domain-név normalizált és tenantonként egyedi', function () {
    $domain = app(CreateDomain::class)('  PELDA.hu ');

    expect($domain->name)->toBe('pelda.hu');

    expect(fn () => app(CreateDomain::class)('pelda.hu'))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('a domain létrehozása auditált', function () {
    app(CreateDomain::class)('pelda.hu');

    $log = AuditLog::query()
        ->where('auditable_type', Domain::class)->latest('id')->first();

    expect($log)->not->toBeNull();
    // A privát kulcs SOHA nem kerül az audit-naplóba.
    expect((string) json_encode($log->after))->not->toContain('PRIVATE KEY');
});
