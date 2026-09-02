<?php

declare(strict_types=1);

use App\Modules\Domains\Actions\CreateDomain;
use App\Modules\Domains\Actions\RotateDkimKey;
use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Jobs\VerifyDomainDns;
use App\Modules\Domains\Testing\FakeDnsResolver;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | A DNS-egyezésnek MECHANIZMUS-szintűnek kell lennie, nem sztring-azonosságnak.
 |
 | Teljes sztring-egyezéssel a helyesen beállított ügyfél SEM tudna küldeni:
 | - akinek már van SPF-je, nem vehet fel másodikat (RFC 7208 tiltja), csak bővítheti;
 | - aki szigorúbb DMARC-ot használ (p=reject), az „nem egyezik" lenne;
 | - a 2048 bites DKIM TXT ~390 karakter, amit a DNS 255-ös darabokra bont.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());

    $this->resolver = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->resolver);

    $this->domain = app(CreateDomain::class)('pelda.hu');
    $this->records = $this->domain->dnsRecords();
});

function publishValid(FakeDnsResolver $resolver, array $records, array $overrides = []): void
{
    foreach ($records as $key => $record) {
        // A `??` operátor a NULL felülbírálást is „nincs megadva"-ként kezelné, ezért
        // kulcs-létezést nézünk: a null azt jelenti, hogy ezt a rekordot NE publikáljuk.
        $value = array_key_exists($key, $overrides) ? $overrides[$key] : $record['value'];

        if ($value === null) {
            continue;
        }

        $resolver->publish($record['name'], $record['type'], $value);
    }
}

it('a meglévő SPF-be BŐVÍTETT include is elfogadott', function () {
    publishValid($this->resolver, $this->records, [
        'spf' => 'v=spf1 include:_spf.google.com include:'.config('domains.spf_include').' ~all',
    ]);

    app(VerifyDomainDns::class)->handle($this->domain);

    expect($this->domain->fresh()->spf_status)->toBe('verified');
});

it('a mi include-unk nélküli SPF elutasítva', function () {
    publishValid($this->resolver, $this->records, ['spf' => 'v=spf1 include:_spf.google.com ~all']);

    app(VerifyDomainDns::class)->handle($this->domain);

    expect($this->domain->fresh()->spf_status)->toBe('failed');
});

it('a SZIGORÚBB DMARC-házirend is elfogadott', function () {
    publishValid($this->resolver, $this->records, [
        'dmarc' => 'v=DMARC1; p=reject; rua=mailto:'.config('domains.dmarc_rua'),
    ]);

    app(VerifyDomainDns::class)->handle($this->domain);

    expect($this->domain->fresh()->dmarc_status)->toBe('verified');
});

it('a DMARC házirend nélkül elutasítva', function () {
    publishValid($this->resolver, $this->records, ['dmarc' => 'v=DMARC1; rua=mailto:valaki@example.test']);

    app(VerifyDomainDns::class)->handle($this->domain);

    expect($this->domain->fresh()->dmarc_status)->toBe('failed');
});

it('a 255 karakterenként darabolt DKIM TXT is elfogadott', function () {
    // A DNS így szállítja: a resolver több darabban adja vissza ugyanazt a rekordot.
    $value = $this->records['dkim']['value'];
    $chunks = str_split($value, 255);

    foreach ($chunks as $chunk) {
        $this->resolver->publish($this->records['dkim']['name'], 'TXT', $chunk);
    }

    publishValid($this->resolver, $this->records, ['dkim' => null]);

    app(VerifyDomainDns::class)->handle($this->domain);

    expect($this->domain->fresh()->dkim_status)->toBe('verified');
});

it('idegen DKIM publikus kulcs elutasítva', function () {
    publishValid($this->resolver, $this->records, [
        'dkim' => 'v=DKIM1; k=rsa; p=IDEGEN-KULCS-ERTEK',
    ]);

    app(VerifyDomainDns::class)->handle($this->domain);

    expect($this->domain->fresh()->dkim_status)->toBe('failed');
});

it('rotáció közben az ELŐZŐ selector is hitelesnek számít', function () {
    // Enélkül a rotáció KIESÉST okozna: az új selector rekordja még nincs kint, tehát a
    // domain `failed` lenne, és minden küldése DOMAIN_UNVERIFIED.
    publishValid($this->resolver, $this->records);
    app(VerifyDomainDns::class)->handle($this->domain);
    expect($this->domain->fresh()->status)->toBe('verified');

    app(RotateDkimKey::class)($this->domain->fresh());

    // Az ügyfél még nem vette fel az ÚJ selector rekordját — a régi viszont kint van.
    app(VerifyDomainDns::class)->handle($this->domain->fresh());

    expect($this->domain->fresh()->status)->toBe('verified', 'a rotáció kiesést okozott');
    expect($this->domain->fresh()->dkim_status)->toBe('rotating');
});

it('az átfedés lejárta után a régi selector már nem tartja életben', function () {
    publishValid($this->resolver, $this->records);
    app(VerifyDomainDns::class)->handle($this->domain);

    app(RotateDkimKey::class)($this->domain->fresh());

    $this->domain->fresh()->forceFill([
        'dkim_rotated_at' => now()->subDays((int) config('domains.dkim_rotation_overlap_days') + 1),
    ])->save();

    app(VerifyDomainDns::class)->handle($this->domain->fresh());

    expect($this->domain->fresh()->status)->toBe('failed');
});
