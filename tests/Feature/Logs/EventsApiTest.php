<?php

declare(strict_types=1);

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | A3 · 27. kritérium — `GET /v1/events` szűréssel és STABIL kurzoros lapozással.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    ['plain' => $this->plain] = ApiKey::issue('CI', ['events:read']);
});

function messageEvent(array $attributes = []): MessageEvent
{
    return MessageEvent::create(array_merge([
        'message_uuid' => (string) Str::uuid(),
        'type' => 'delivered',
        'recipient' => 'cimzett@example.test',
        'channel' => 'transactional',
        'tags' => ['szamla'],
        'variables' => [],
    ], $attributes));
}

function events(array $query = []): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->plain,
        'Accept' => 'application/json',
    ])->getJson('/v1/events?'.http_build_query($query));
}

it('visszaadja az eseményeket, legfrissebb elöl', function () {
    $regi = messageEvent(['recipient' => 'regi@example.test']);
    $this->travelTo(now()->addMinute());
    $uj = messageEvent(['recipient' => 'uj@example.test']);

    $response = events()->assertOk();

    expect($response->json('items.0.recipient'))->toBe('uj@example.test');
    expect($response->json('items.1.recipient'))->toBe('regi@example.test');
});

it('szűr típusra, címzettre, tagre és csatornára', function () {
    messageEvent(['type' => 'delivered', 'recipient' => 'a@example.test', 'tags' => ['szamla']]);
    messageEvent(['type' => 'bounced', 'recipient' => 'b@example.test', 'tags' => ['hirlevel'], 'channel' => 'marketing']);

    expect(events(['type' => 'bounced'])->json('items'))->toHaveCount(1);
    expect(events(['recipient' => 'a@example.test'])->json('items'))->toHaveCount(1);
    expect(events(['tag' => 'hirlevel'])->json('items.0.type'))->toBe('bounced');
    expect(events(['channel' => 'marketing'])->json('items'))->toHaveCount(1);
});

it('szűr a v: változóra — az integrációk ebből azonosítják a saját rekordjaikat', function () {
    messageEvent(['variables' => ['rendeles' => 'A-42']]);
    messageEvent(['variables' => ['rendeles' => 'B-7']]);

    $response = events(['variable' => ['rendeles' => 'A-42']])->assertOk();

    expect($response->json('items'))->toHaveCount(1);
    expect($response->json('items.0.variables.rendeles'))->toBe('A-42');
});

it('a KURZOROS lapozás akkor is stabil, ha közben új esemény keletkezik', function () {
    // Ez a lényeg: offset-lapozásnál a második oldal lekérésekor a sorok elcsúsznának, és a
    // hívó ugyanazt kapná kétszer — egy suppression-szinkron ebből rossz adatot építene.
    foreach (range(1, 5) as $index) {
        messageEvent(['recipient' => "c{$index}@example.test"]);
        $this->travelTo(now()->addSecond());
    }

    $first = events(['limit' => 2])->assertOk();

    expect($first->json('items'))->toHaveCount(2);
    expect($first->json('paging.next'))->not->toBeNull();

    // KÖZBEN új esemény érkezik — a kurzor mögé nem szabad beszúródnia.
    messageEvent(['recipient' => 'friss@example.test']);

    $second = events(['limit' => 2, 'cursor' => $first->json('paging.next')])->assertOk();

    $seen = array_merge(
        array_column($first->json('items'), 'recipient'),
        array_column($second->json('items'), 'recipient')
    );

    expect($seen)->toBe(['c5@example.test', 'c4@example.test', 'c3@example.test', 'c2@example.test']);
    expect(count(array_unique($seen)))->toBe(4);
});

it('az UGYANABBAN a másodpercben keletkezett események is teljesen lapozhatók', function () {
    // Az `occurred_at` önmagában nem egyedi: egy 1000-es batch eseményei ugyanabban a
    // másodpercben keletkeznek. Ha a kurzor csak az időbélyeg lenne, sorok maradnának ki.
    foreach (range(1, 6) as $index) {
        messageEvent(['recipient' => "batch{$index}@example.test"]);
    }

    $seen = [];
    $cursor = null;

    do {
        $page = events(array_filter(['limit' => 2, 'cursor' => $cursor]))->assertOk();

        $seen = array_merge($seen, array_column($page->json('items'), 'recipient'));
        $cursor = $page->json('paging.next');
    } while ($cursor !== null);

    expect(count(array_unique($seen)))->toBe(6);
});

it('az érvénytelen kurzor strukturált hiba', function () {
    events(['cursor' => 'nem-valid'])->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('events:read scope nélkül 403', function () {
    ['plain' => $plain] = ApiKey::issue('Szűk', ['messages:send']);

    $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'])
        ->getJson('/v1/events')
        ->assertStatus(403);
});

it('idegen workspace eseményei nem látszanak', function () {
    messageEvent(['recipient' => 'sajat@example.test']);

    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);
    ['plain' => $idegen] = ApiKey::issue('Idegen', ['events:read']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$idegen, 'Accept' => 'application/json'])
        ->getJson('/v1/events')
        ->assertOk();

    expect($response->json('items'))->toBeEmpty();
});
