<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | A1 elfogadási kritérium #1–#10 — a Mailgun Messages API paritása.
 |
 | Ez az a felület, amire a meglévő integrációk épülnek: a `/v1` ami egyszer kimegy, az
 | SZERZŐDÉS (golden rule 7). Minden mező-döntés itt végleges.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    ['model' => $this->key, 'plain' => $this->plain] = ApiKey::issue('CI', ['messages:send', 'templates:write']);
});

function apiPost(string $uri, array $payload = [], array $headers = []): TestResponse
{
    return test()->withHeaders(array_merge([
        'Authorization' => 'Bearer '.test()->plain,
        'Idempotency-Key' => 'kulcs-'.bin2hex(random_bytes(6)),
        'Accept' => 'application/json',
    ], $headers))->postJson($uri, $payload);
}

function validPayload(array $overrides = []): array
{
    return array_merge([
        'from' => 'ventMail <no-reply@pelda.hu>',
        'to' => 'cimzett@example.test',
        'subject' => 'Teszt',
        'html' => '<p>Szia</p>',
    ], $overrides);
}

it('strukturált küldés elfogadva és az üzenet azonosítója visszajön', function () {
    $response = apiPost('/v1/messages', validPayload());

    $response->assertAccepted()
        ->assertJsonStructure(['id', 'message', 'status']);

    expect(Message::count())->toBe(1);
    expect($response->json('status'))->toBe('delivered'); // FakeDriver
});

it('hitelesítés nélkül 401', function () {
    $this->withHeaders(['Accept' => 'application/json'])
        ->postJson('/v1/messages', validPayload())
        ->assertUnauthorized();
});

it('hiányzó scope esetén 403', function () {
    ['plain' => $plain] = ApiKey::issue('Szűk', ['logs:read']);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$plain,
        'Idempotency-Key' => 'k1',
        'Accept' => 'application/json',
    ])->postJson('/v1/messages', validPayload())->assertForbidden();
});

it('Idempotency-Key nélkül 400', function () {
    // Golden rule 5: a küldő POST-on KÖTELEZŐ.
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plain, 'Accept' => 'application/json'])
        ->postJson('/v1/messages', validPayload())
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
});

it('ismételt Idempotency-Key ugyanazt a választ adja, és nem küld újra', function () {
    $first = apiPost('/v1/messages', validPayload(), ['Idempotency-Key' => 'ugyanaz']);
    $second = apiPost('/v1/messages', validPayload(), ['Idempotency-Key' => 'ugyanaz']);

    expect($second->json('id'))->toBe($first->json('id'));
    expect(Message::count())->toBe(1);
    expect(MessageEvent::where('type', 'delivered')->count())->toBe(1);
});

it('verifikálatlan domainről a gate elutasít, strukturált kóddal', function () {
    Domain::query()->update(['status' => 'pending']);

    apiPost('/v1/messages', validPayload())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'DOMAIN_UNVERIFIED');
});

it('a gate-üzenet a FORDÍTÓ-RÉTEGEN megy — a kód marad, a szöveg követi a nyelvet', function () {
    /*
     | C2 · 89. kritérium. A hat nyelv kulcsai HASZNÁLAT NÉLKÜL álltak: a
     | `LocaleCompletenessTest` őrizte a teljességüket, miközben az API beégetett magyar
     | szöveggel válaszolt. Egy fordítás, amit semmi nem hív, nem fordítás.
     |
     | A KÓD a szerződés (golden rule 7), ezért az MINDEN nyelven ugyanaz — a `message`
     | viszont a beállított nyelven jön, és pontosan a lang-fájl szövege.
     */
    Domain::query()->update(['status' => 'pending']);

    /*
     | A NYELVET A KÉRÉS választja, nem a teszt: az `Accept-Language` fejlécen megy, amit a
     | `SetLocale` middleware a támogatott listára szűr. Így a teszt a VALÓDI úton mér — az
     | első változat maga hívta a `setLocale()`-t, tehát átment volna akkor is, amikor (mint
     | mérve is) a kódbázisban egyetlen nyelvválasztó hívás sem volt.
     */
    foreach (['hu', 'en', 'de'] as $locale) {
        apiPost('/v1/messages', validPayload(), ['Accept-Language' => $locale])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DOMAIN_UNVERIFIED')
            ->assertJsonPath('error.message', trans('ventmail.gate.domain_unverified', locale: $locale));
    }

    // A RÉGIÓ-VÁLTOZAT az alapnyelvre vezet: `hu-HU` → `hu`.
    apiPost('/v1/messages', validPayload(), ['Accept-Language' => 'hu-HU,hu;q=0.9'])
        ->assertJsonPath('error.message', trans('ventmail.gate.domain_unverified', locale: 'hu'));

    // NEM TÁMOGATOTT nyelv → az alap, nem hiba és nem nyers kulcs.
    apiPost('/v1/messages', validPayload(), ['Accept-Language' => 'zz'])
        ->assertJsonPath('error.message', trans('ventmail.gate.domain_unverified', locale: (string) config('app.locale')));

    // ISMERETLEN kód esetén sem szivárog nyers kulcs a válaszba: az általános üzenet jön.
    expect(trans('ventmail.gate.rejected'))->not->toContain('ventmail.gate');
});

it('a feladó neve és címe külön is megadható', function () {
    apiPost('/v1/messages', validPayload(['from' => 'no-reply@pelda.hu']))->assertAccepted();

    expect(Message::first()->from_email)->toBe('no-reply@pelda.hu');
});

it('a batch max 1000 címzettet enged', function () {
    $recipients = array_map(fn ($i) => "cimzett{$i}@example.test", range(1, 1001));

    // Formai hiba (a kérés soha nem lett volna teljesíthető) → 400, ahogy a Mailgun is adja.
    apiPost('/v1/messages', validPayload(['to' => $recipients]))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'TOO_MANY_RECIPIENTS');
});

it('a batch címzettenkénti eseményt ír, azonos üzenet-azonosítóval', function () {
    // A tartalom-oldali bizonyítás a BatchSendTestben van (a küldési úton mérve); itt a
    // napló szerkezetét nézzük: egy üzenet, címzettenkénti esemény, lenyomattal.
    apiPost('/v1/messages', validPayload([
        'to' => ['anna@example.test', 'bela@example.test'],
        'html' => '<p>Szia %recipient.first_name%</p>',
        'recipient-variables' => json_encode([
            'anna@example.test' => ['first_name' => 'Anna'],
            'bela@example.test' => ['first_name' => 'Béla'],
        ]),
    ]))->assertAccepted();

    $message = Message::firstOrFail();
    $events = MessageEvent::where('type', 'delivered')->get();

    expect($events)->toHaveCount(2);
    expect($events->pluck('message_uuid')->unique())->toHaveCount(1);
    // A lenyomat CÍMZETTENKÉNT KÜLÖNBÖZŐ: bizonyítja, hogy más törzs ment ki, de nem
    // őrzi meg a tartalmat.
    expect($events->pluck('payload.body_sha256')->unique())->toHaveCount(2);
    expect($message->variables)->toHaveKey('recipient-variables');
});
