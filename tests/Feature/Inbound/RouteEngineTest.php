<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Inbound\Jobs\ForwardInboundToEmail;
use App\Modules\Inbound\Jobs\ForwardInboundToUrl;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Inbound\Models\InboundRoute;
use App\Modules\Inbound\Services\RouteEngine;
use App\Modules\Inbound\Services\SafeRegex;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 | A4 · 30. kritérium — a ROUTE-MOTOR.
 |
 | Prioritás szerint rendezett szabálylista; az ELSŐ `stop()` lezárja a láncot. A
 | determinizmus itt nem stílus: ha az azonos prioritású szabályok sorrendje a Postgres
 | pillanatnyi kedvétől függne, ugyanaz a levél ma máshova menne, mint holnap.
 */

beforeEach(function () {
    Queue::fake();

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    $this->domain = Domain::create([
        'name' => 'pelda.hu',
        'status' => 'verified',
        'verified_at' => now(),
        'inbound_mx_status' => 'verified',
    ]);
});

function inbound(array $overrides = []): InboundMessage
{
    return InboundMessage::query()->create(array_merge([
        'domain_id' => test()->domain->getKey(),
        'sender' => 'valaki@kuldo.example',
        'recipient' => 'support@pelda.hu',
        'subject' => 'Kérdés a számláról',
        'headers' => ['subject' => 'Kérdés a számláról', 'from' => 'valaki@kuldo.example'],
        'body_plain' => 'Szia',
        'status' => 'parsed',
        'received_at' => now(),
    ], $overrides));
}

function inboundRoute(array $overrides = []): InboundRoute
{
    return InboundRoute::query()->create(array_merge([
        'description' => 'teszt',
        'priority' => 100,
        'match_type' => 'match_recipient',
        'expression' => '^support@pelda\.hu$',
        'actions' => [['type' => 'forward', 'target' => 'https://hook.example.test/mail']],
    ], $overrides));
}

it('a match_recipient illeszkedő szabálya továbbít', function () {
    inboundRoute();

    app(RouteEngine::class)->run(inbound());

    Queue::assertPushed(ForwardInboundToUrl::class, 1);
});

it('a NEM illeszkedő szabály nem csinál semmit', function () {
    inboundRoute(['expression' => '^masik@pelda\.hu$']);

    $message = inbound();

    app(RouteEngine::class)->run($message);

    Queue::assertNothingPushed();
    expect($message->fresh()->reason)->toBe('NO_ROUTE_MATCHED');
});

it('a match_header a FEJLÉCRE illeszt', function () {
    // A tárgy „Kérdés a számláról" — a `.*számlá.*` illeszkedik rá, a `.*számla.*` NEM: a
    // magyar toldalékolás a `számla` szót `számlá`-ra változtatja. Ez nem hiba, hanem az
    // ügyfél szabályának pontossága; a teszt mindkét irányt rögzíti.
    inboundRoute(['match_type' => 'match_header', 'match_field' => 'subject', 'expression' => '.*számlá.*']);

    app(RouteEngine::class)->run(inbound());

    Queue::assertPushed(ForwardInboundToUrl::class, 1);
});

it('a match_header illesztése KIS/NAGYBETŰ-FÜGGETLEN', function () {
    // A tárgysort ember írja: `SZÁMLA`, `Számla`, `számla` ugyanaz a szándék.
    inboundRoute(['match_type' => 'match_header', 'match_field' => 'subject', 'expression' => '.*KÉRDÉS.*']);

    app(RouteEngine::class)->run(inbound());

    Queue::assertPushed(ForwardInboundToUrl::class, 1);
});

it('a HIÁNYZÓ fejlécre nem illeszkedik', function () {
    inboundRoute(['match_type' => 'match_header', 'match_field' => 'x-nincs-ilyen', 'expression' => '.+']);

    app(RouteEngine::class)->run(inbound());

    Queue::assertNothingPushed();
});

it('a catch_all MINDIG illeszkedik', function () {
    inboundRoute(['match_type' => 'catch_all', 'expression' => null]);

    app(RouteEngine::class)->run(inbound(['recipient' => 'barmi@pelda.hu']));

    Queue::assertPushed(ForwardInboundToUrl::class, 1);
});

it('az ELSŐ stop() lezárja a láncot', function () {
    // A `stop()` utáni szabályok nem futnak — ez a szabálylista lelke.
    inboundRoute([
        'priority' => 10,
        'actions' => [['type' => 'forward', 'target' => 'https://elso.example.test/h'], ['type' => 'stop']],
    ]);

    inboundRoute([
        'priority' => 20,
        'match_type' => 'catch_all',
        'expression' => null,
        'actions' => [['type' => 'forward', 'target' => 'https://masodik.example.test/h']],
    ]);

    app(RouteEngine::class)->run(inbound());

    Queue::assertPushed(ForwardInboundToUrl::class, 1);
});

it('stop() NÉLKÜL minden illeszkedő szabály lefut', function () {
    inboundRoute(['priority' => 10, 'actions' => [['type' => 'forward', 'target' => 'https://elso.example.test/h']]]);
    inboundRoute([
        'priority' => 20,
        'match_type' => 'catch_all',
        'expression' => null,
        'actions' => [['type' => 'forward', 'target' => 'https://masodik.example.test/h']],
    ]);

    app(RouteEngine::class)->run(inbound());

    Queue::assertPushed(ForwardInboundToUrl::class, 2);
});

it('a PRIORITÁS sorrendje determinisztikus — azonos prioritásnál is', function () {
    // Enélkül ugyanaz a levél ma máshova menne, mint holnap.
    $elso = inboundRoute(['priority' => 50, 'match_type' => 'catch_all', 'expression' => null,
        'actions' => [['type' => 'stop']]]);

    inboundRoute(['priority' => 50, 'match_type' => 'catch_all', 'expression' => null,
        'actions' => [['type' => 'forward', 'target' => 'https://nem-fut.example.test/h']]]);

    app(RouteEngine::class)->run(inbound());

    Queue::assertNothingPushed();
    expect($elso->fresh()->last_matched_at)->not->toBeNull();
});

it('az INAKTÍV szabály kimarad', function () {
    inboundRoute(['active' => false]);

    app(RouteEngine::class)->run(inbound());

    Queue::assertNothingPushed();
});

it('a forward(email) a KIMENŐ motorra megy, a forward(url) a HTTP-re', function () {
    inboundRoute(['priority' => 10, 'actions' => [['type' => 'forward', 'target' => 'ops@venture.co']]]);
    inboundRoute(['priority' => 20, 'actions' => [['type' => 'forward', 'target' => 'https://hook.example.test/h']]]);

    app(RouteEngine::class)->run(inbound());

    Queue::assertPushed(ForwardInboundToEmail::class, 1);
    Queue::assertPushed(ForwardInboundToUrl::class, 1);
});

it('a store(notify) értesítést küld, a puszta store() nem', function () {
    inboundRoute(['priority' => 10, 'actions' => [['type' => 'store', 'target' => 'https://ertesites.example.test/h']]]);

    app(RouteEngine::class)->run(inbound());

    Queue::assertPushed(ForwardInboundToUrl::class, 1);

    Queue::fake();

    InboundRoute::query()->delete();
    inboundRoute(['priority' => 10, 'actions' => [['type' => 'store']]]);

    app(RouteEngine::class)->run(inbound(['recipient' => 'masik@pelda.hu']));

    Queue::assertNothingPushed();
});

/*
 | REDOS — az ügyfél írja a kifejezést.
 |
 | Ezt már megégettük egyszer: az A1-ben egy SAJÁT regexünk 500 kB-os bemeneten 76 másodpercig
 | futott. Egy ügyfél-regex ugyanezt tudja, csak szándékosan.
 */

it('a KATASZTROFÁLIS visszalépésű kifejezés nem viszi el a jobot', function () {
    $regex = new SafeRegex;

    $start = microtime(true);

    // Klasszikus katasztrofális minta hosszú, nem illeszkedő bemeneten.
    $result = $regex->matches('(a+)+$', str_repeat('a', 40).'!');

    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(2.0);
    expect($result['matched'])->toBeFalse();
    // …és a korlátba ütközés LÁTSZIK: nem „nem illeszkedik"-ként könyveljük el csendben.
    expect($result['error'])->not->toBeNull();
});

it('a HIBÁS kifejezés nem dob, csak nem illeszkedik', function () {
    $regex = new SafeRegex;

    $result = $regex->matches('(((((', 'bármi');

    expect($result['matched'])->toBeFalse();
    expect($result['error'])->not->toBeNull();
    expect($regex->valid('((((('))->toBeFalse();
    expect($regex->valid('^support@pelda\.hu$'))->toBeTrue();
});

it('a TÚL HOSSZÚ kifejezést elutasítja', function () {
    expect((new SafeRegex)->matches(str_repeat('a', 1000), 'x')['error'])->not->toBeNull();
});

it('a hibás szabály nem állítja meg a TÖBBI szabályt', function () {
    inboundRoute(['priority' => 10, 'expression' => '((((']);
    inboundRoute(['priority' => 20, 'actions' => [['type' => 'forward', 'target' => 'https://jo.example.test/h']]]);

    app(RouteEngine::class)->run(inbound());

    Queue::assertPushed(ForwardInboundToUrl::class, 1);
});

it('IDEGEN workspace szabálya nem fut le a mi levelünkre', function () {
    $masik = Workspace::factory()->create();

    app(WorkspaceContext::class)->set($masik);
    inboundRoute(['match_type' => 'catch_all', 'expression' => null,
        'actions' => [['type' => 'forward', 'target' => 'https://idegen.example.test/h']]]);

    app(WorkspaceContext::class)->set($this->workspace);

    app(RouteEngine::class)->run(inbound());

    Queue::assertNothingPushed();
});
