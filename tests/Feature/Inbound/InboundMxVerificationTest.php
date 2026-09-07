<?php

declare(strict_types=1);

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Testing\FakeDnsResolver;
use App\Modules\Inbound\Actions\VerifyInboundMx;
use App\Modules\Inbound\Services\VerifiedInboundResolver;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | A4 · 37. kritérium — az MX-rekord ellenőrzése, a fogadás BIZALMI HORGONYA.
 |
 | Ez az egyetlen mechanizmus, ami eldönti, hogy egy domainre szabad-e levelet fogadnunk. Ha
 | bárki bármelyik domaint `verified`-re állíthatná, mindenki más levelét is elfogadnánk.
 */

beforeEach(function () {
    $this->dns = new FakeDnsResolver;
    $this->app->instance(DnsResolver::class, $this->dns);

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    config(['inbound.mx_target' => 'mx.ventmail.app']);
});

function inboundDomain(array $overrides = []): Domain
{
    return Domain::create(array_merge([
        'name' => 'pelda.hu',
        'status' => 'verified',
        'verified_at' => now(),
        'inbound_mx_status' => 'pending',
    ], $overrides));
}

it('a RÁNK mutató MX-rekordot elfogadja', function () {
    $this->dns->publish('pelda.hu', 'MX', '10 mx.ventmail.app.');

    expect(app(VerifyInboundMx::class)(inboundDomain()))->toBe('verified');
});

it('a prioritás és a záró pont NEM számít', function (string $record) {
    $this->dns->publish('pelda.hu', 'MX', $record);

    expect(app(VerifyInboundMx::class)(inboundDomain()))->toBe('verified');
})->with([
    'záró ponttal' => '10 mx.ventmail.app.',
    'záró pont nélkül' => '10 mx.ventmail.app',
    'más prioritással' => '50 mx.ventmail.app.',
    'nagybetűsen' => '10 MX.VENTMAIL.APP.',
]);

it('az IDEGEN MX-rekordot elutasítja', function () {
    // Aki máshova irányítja a levelét, annak nem mi fogadunk.
    $this->dns->publish('pelda.hu', 'MX', '10 mx.idegen-szolgaltato.example.');

    $domain = inboundDomain();

    expect(app(VerifyInboundMx::class)($domain))->toBe('failed');
    expect($domain->fresh()->inbound_mx_status)->toBe('failed');
});

it('MX-rekord NÉLKÜL is elutasítja', function () {
    // EXPLICIT blackhole: publikálás nélkül a fake alapértelmezésből adna MX-et, és a teszt
    // attól lenne zöld, hogy az MX IDEGEN — vagyis a fölötte lévő esetet ismételné meg.
    $this->dns->blackhole('pelda.hu');

    expect(app(VerifyInboundMx::class)(inboundDomain()))->toBe('failed');
});

it('a KÜLDÉSHEZ nem hitelesített domain csak `pending` lehet', function () {
    // Az MX-rekord önmagában csak azt mutatja, hogy VALAKI hozzánk irányítja a levelet. A
    // TULAJDONLÁST a küldő-oldali DKIM/SPF/DMARC hármas igazolja — enélkül bárki ránk
    // irányíthatná a szomszéd domainjét, és megkapná a leveleit.
    $this->dns->publish('pelda.hu', 'MX', '10 mx.ventmail.app.');

    $domain = inboundDomain(['status' => 'failed', 'verified_at' => null]);

    expect(app(VerifyInboundMx::class)($domain))->toBe('pending');

    // …és a fogadás NEM indul el.
    expect((new VerifiedInboundResolver)->resolve('info@pelda.hu'))->toBeNull();
});

it('a VISSZAESÉS is kezelt: az MX eltűnésével a fogadás megszűnik', function () {
    // „Egyszer verified, mindig verified" azt jelentené, hogy egy eladott vagy elhagyott
    // domain leveleit örökre elfogadjuk.
    $this->dns->publish('pelda.hu', 'MX', '10 mx.ventmail.app.');

    $domain = inboundDomain();

    app(VerifyInboundMx::class)($domain);

    app(WorkspaceContext::class)->forget();
    expect((new VerifiedInboundResolver)->resolve('info@pelda.hu'))->not->toBeNull();

    app(WorkspaceContext::class)->set($this->workspace);
    $this->dns->withdraw('pelda.hu', 'MX');

    expect(app(VerifyInboundMx::class)($domain->fresh()))->toBe('failed');

    app(WorkspaceContext::class)->forget();
    expect((new VerifiedInboundResolver)->resolve('info@pelda.hu'))->toBeNull();
});

it('a `disabled` domaint az ütemezett futás nem bántja', function () {
    // Aki nem kér bejövő fogadást, azt nem kezdjük el magunktól ellenőrizni.
    $domain = inboundDomain(['inbound_mx_status' => 'disabled']);

    $this->artisan('inbound:verify-mx')->assertSuccessful();

    expect($domain->fresh()->inbound_mx_status)->toBe('disabled');
});

it('a wizard KIÍRJA a szükséges MX-rekordot', function () {
    // Az ügyfél nem tudhatja, mit kell beállítania, ha nem mondjuk meg neki.
    $records = inboundDomain()->dnsRecords();

    expect($records)->toHaveKey('inbound_mx');
    expect($records['inbound_mx']['type'])->toBe('MX');
    expect($records['inbound_mx']['value'])->toContain('mx.ventmail.app');
    expect($records['inbound_mx']['purpose'])->toContain('opcionális');
});
