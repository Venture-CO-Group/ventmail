<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Inbound\Protocol\MxSession;
use App\Modules\Inbound\Services\InboundIngest;
use App\Modules\Inbound\Services\VerifiedInboundResolver;
use App\Modules\Inbound\Testing\FakeRawMailStore;
use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 | A4 · 37. kritérium — TENANT-IZOLÁCIÓ a bejövő oldalon.
 |
 | A `VerifiedInboundResolver` az EGYETLEN hely, ahol a tenant-hozzárendelés eldől, és ez az
 | egyetlen lekérdezés, ami a workspace-scope-ot megkerüli (fogadáskor még nincs kontextus).
 | Ezért itt nem lehet se nemdeterminizmus, se rés.
 */

beforeEach(function () {
    Queue::fake();

    $this->store = new FakeRawMailStore;
    $this->app->instance(RawMailStore::class, $this->store);

    $this->resolver = new VerifiedInboundResolver;
});

function verifiedDomain(Workspace $workspace, string $name): Domain
{
    app(WorkspaceContext::class)->set($workspace);

    $domain = Domain::create([
        'name' => $name,
        'status' => 'verified',
        'verified_at' => now(),
        'inbound_mx_status' => 'verified',
    ]);

    app(WorkspaceContext::class)->forget();

    return $domain;
}

it('a levél ahhoz a tenanthoz kerül, akié a domain', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    verifiedDomain($a, 'egyik.hu');
    verifiedDomain($b, 'masik.hu');

    expect($this->resolver->resolve('info@egyik.hu')?->workspaceId)->toBe((int) $a->getKey());
    expect($this->resolver->resolve('info@masik.hu')?->workspaceId)->toBe((int) $b->getKey());
});

it('a TÖRÖLT domainre nem fogadunk többé', function () {
    // A `withoutGlobalScopes()` ARGUMENTUM NÉLKÜL a SoftDeletingScope-ot is leszedné, és a
    // törölt domainre továbbra is folyna be a levél — olyan retenció alatt, amihez már
    // nincs jogalapunk.
    $workspace = Workspace::factory()->create();
    $domain = verifiedDomain($workspace, 'megszunt.hu');

    expect($this->resolver->resolve('info@megszunt.hu'))->not->toBeNull();

    app(WorkspaceContext::class)->set($workspace);
    $domain->delete();
    app(WorkspaceContext::class)->forget();

    expect($this->resolver->resolve('info@megszunt.hu'))->toBeNull();
});

it('UGYANAZ a domain nem lehet két tenantnál verifikált', function () {
    // A `domains` unique kulcsa `(workspace_id, name)`, tehát a séma önmagában megengedné —
    // és akkor a feloldás önkényesen az egyik tenantot választaná, a MÁSIK tenant levele
    // pedig idegen workspace-be kerülne (golden rule 6). A részleges unique index zárja ki.
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    verifiedDomain($a, 'kozos.hu');

    expect(fn () => verifiedDomain($b, 'kozos.hu'))
        ->toThrow(QueryException::class);
});

it('a KÉTÉRTELMŰ tulajdonlásnál inkább nem fogadunk', function () {
    // Ha a séma-védelem valaha elromlana (migráció, kézi beavatkozás), a feloldás NEM
    // tippel: az idegen tenanthoz kerülő levél rosszabb, mint az elutasított.
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    verifiedDomain($a, 'kozos.hu');

    // A séma-védelem megkerülésével — pontosan azt szimulálva, amit meg akarunk előzni.
    DB::statement('drop index if exists inbound_domain_unique');

    verifiedDomain($b, 'kozos.hu');

    expect($this->resolver->resolve('info@kozos.hu'))->toBeNull();
});

it('a bejövő levél NEM látszik a másik tenantnál', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    $domain = verifiedDomain($a, 'egyik.hu');

    $ingest = new InboundIngest($this->store, app(WorkspaceContext::class));

    $session = new MxSession(
        $this->resolver,
        $ingest,
        ChannelSecurity::encrypted('TLSv1.3'),
        'mx.test',
    );

    foreach (['EHLO kuldo.example', 'MAIL FROM:<a@kuldo.example>', 'RCPT TO:<info@egyik.hu>', 'DATA', 'Subject: x', '', 'y', '.'] as $line) {
        $session->feed($line);
    }

    app(WorkspaceContext::class)->set($a);
    expect(InboundMessage::query()->count())->toBe(1);
    expect(InboundMessage::query()->firstOrFail()->domain_id)->toBe((int) $domain->getKey());

    app(WorkspaceContext::class)->set($b);
    expect(InboundMessage::query()->count())->toBe(0);
});

it('EGY levél KÉT tenant címzettjének KÉT külön sort ad', function () {
    // Egy közös sor a két tenant adatát keverné.
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    verifiedDomain($a, 'egyik.hu');
    verifiedDomain($b, 'masik.hu');

    $ingest = new InboundIngest($this->store, app(WorkspaceContext::class));

    $session = new MxSession($this->resolver, $ingest, ChannelSecurity::encrypted('TLSv1.3'), 'mx.test');

    foreach ([
        'EHLO kuldo.example', 'MAIL FROM:<a@kuldo.example>',
        'RCPT TO:<info@egyik.hu>', 'RCPT TO:<info@masik.hu>',
        'DATA', 'Subject: Közös', '', 'törzs', '.',
    ] as $line) {
        $session->feed($line);
    }

    foreach ([$a, $b] as $workspace) {
        app(WorkspaceContext::class)->set($workspace);

        expect(InboundMessage::query()->count())->toBe(1);
    }
});

/*
 | A BORÍTÉK-CÍM validációja — a tárolt cím nem térhet el a feloldottól.
 */

it('a VEZÉRLŐKARAKTERES címet elutasítja', function (string $address) {
    $workspace = Workspace::factory()->create();
    verifiedDomain($workspace, 'pelda.hu');

    $session = new MxSession(
        $this->resolver,
        new InboundIngest($this->store, app(WorkspaceContext::class)),
        ChannelSecurity::encrypted('TLSv1.3'),
        'mx.test',
    );

    $session->feed('EHLO kuldo.example');
    $session->feed('MAIL FROM:<a@kuldo.example>');

    $replies = $session->feed('RCPT TO:<'.$address.'>');

    expect($replies[0]->code)->toBe(501);
})->with([
    // A Postgres a NUL-nál elvágja: a TÁROLT címzett `in` lenne, a feloldott `info@pelda.hu`
    // — a tenant `match_recipient` szabályai nem illeszkednének, és a feladó-attribúció is
    // megsemmisülne.
    'NUL' => "in\x00fo@pelda.hu",
    'CR' => "info\rx@pelda.hu",
    'LF' => "info\nx@pelda.hu",
    'TAB' => "info\tx@pelda.hu",
    'szóköz' => 'info @pelda.hu',
    'két cím' => 'info@pelda.hu, masik@pelda.hu',
    'source route' => '@idegen.example:info@pelda.hu',
    'kukac nélkül' => 'info.pelda.hu',
    'túl hosszú' => 'info'.str_repeat('x', 300).'@pelda.hu',
]);
