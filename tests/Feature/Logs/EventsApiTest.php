<?php

declare(strict_types=1);

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;

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

/*
 | BEMENET-VALIDÁCIÓ — a `/v1` egységes hibaformátuma (golden rule 7).
 |
 | Validáció nélkül ezek NYERS 500-at adnak `error.code` nélkül: a `since=tegnap` egy
 | `InvalidFormatException`-ön, a `recipient[]=a` egy „Array to string conversion"-ön át. Az
 | integrációk a `error.code`-ra ágaznak — egy 500-as HTML-hibaoldalra nem tudnak.
 */

it('az érvénytelen paraméter strukturált 400, nem 500', function (array $query) {
    $response = events($query);

    expect($response->status())->toBe(400);
    expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
})->with([
    'értelmezhetetlen dátum' => [['since' => 'tegnap']],
    'tömb ott, ahol skalár kell' => [['recipient' => ['a', 'b']]],
    'beágyazott tömb a típusban' => [['type' => [['x']]]],
    'ismeretlen eseménytípus' => [['type' => 'nincs-ilyen']],
    'ismeretlen csatorna' => [['channel' => 'akarmi']],
    'nulla limit' => [['limit' => 0]],
    'túl nagy limit' => [['limit' => 5000]],
    'nem szám limit' => [['limit' => 'sok']],
]);

it('a skalár típus-szűrő továbbra is működik', function () {
    messageEvent(['type' => 'delivered']);
    messageEvent(['type' => 'bounced']);

    expect(events(['type' => 'delivered'])->json('items'))->toHaveCount(1);
    expect(events(['type' => ['delivered', 'bounced']])->json('items'))->toHaveCount(2);
});

it('a címzett-szűrő az ÉKEZETES alakot is megtalálja', function () {
    // Tárolva punycode-ban van; az ügyfél az olvashatót írja be.
    messageEvent(['recipient' => 'user@xn--plda-bpa.hu']);

    expect(events(['recipient' => 'user@példa.hu'])->json('items'))->toHaveCount(1);
});

/*
 | A KURZOR ÁTLÁTSZATLAN, és az esemény azonosítója UUID.
 |
 | A belső `id` egy TENANT-FÜGGETLEN szekvencia. Nyíltan kiadva bárki két hívásból (09:00 →
 | 10:00) megbecsülné a platform teljes esemény-volumenét — és ez `/v1`-szerződés, tehát
 | később már nem javítható.
 */

it('az esemény azonosítója UUID, nem sorszám', function () {
    $event = messageEvent();

    $id = events()->json('items.0.id');

    expect($id)->toBe($event->uuid);
    expect($id)->not->toBe((string) $event->id);
    expect(Str::isUuid($id))->toBeTrue();
});

it('a kurzor nem árulja el a belső sorszámot', function () {
    foreach (range(1, 3) as $ignored) {
        messageEvent();
    }

    $cursor = (string) events(['limit' => 1])->json('paging.next');

    expect($cursor)->not->toBe('');
    // Sem nyersen, sem base64-ben nem olvasható ki belőle a szekvencia.
    expect(base64_decode($cursor, false) ?: '')->not->toContain('|');

    $ids = MessageEvent::query()->pluck('id')->map(fn ($id) => (string) $id);

    foreach ($ids as $id) {
        expect($cursor)->not->toContain($id);
    }

    // …és attól még LAPOZ.
    expect(events(['limit' => 1, 'cursor' => $cursor])->json('items'))->toHaveCount(1);
});

it('idegen kulccsal kiadott kurzor érvénytelen, nem 500', function () {
    messageEvent();

    $response = events(['cursor' => base64_encode('2026-09-04T10:00:00+00:00|999')]);

    expect($response->status())->toBe(400);
    expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
});

it('a válasz mezői EGYEZNEK az OpenAPI Event sémájával', function () {
    // A `98cadc6a` int-ről uuid-re váltotta az `id`-t, a séma maradt `integer` — az SDK
    // `number`-t generált volna a string mellé. A drift ITT derüljön ki, ne az integrátornál
    // (golden rule 7: OpenAPI ↔ SDK drift = 0).
    messageEvent(['reason' => 'HARD_BOUNCE', 'variables' => ['rendeles' => 'A-42']]);

    $item = events()->json('items.0');

    $spec = Yaml::parseFile(base_path('docs/openapi/ventmail-v1.yaml'));
    $schema = $spec['components']['schemas']['Event']['properties'];

    foreach ($item as $key => $value) {
        expect(array_key_exists($key, $schema))
            ->toBeTrue("a `{$key}` mező nincs dokumentálva az Event sémában");

        $declared = $schema[$key]['type'] ?? null;

        if ($declared === null || $value === null) {
            continue;
        }

        $allowed = array_map(
            fn (string $type) => match ($type) {
                'integer' => 'integer',
                'number' => 'double',
                'string' => 'string',
                'boolean' => 'boolean',
                'array' => 'array',
                'object' => 'array',
                'null' => 'NULL',
                default => $type,
            },
            (array) $declared,
        );

        // `toContain` varargs-ot vár, tehát üzenetet nem fűzhetünk hozzá — az eltérést a
        // mező nevével együtt külön állítjuk.
        expect(in_array(gettype($value), $allowed, true))
            ->toBeTrue("a `{$key}` típusa (".gettype($value).') eltér a szerződéstől ('.implode('|', $allowed).')');
    }
});
