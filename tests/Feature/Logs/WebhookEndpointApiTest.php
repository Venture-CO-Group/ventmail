<?php

declare(strict_types=1);

use App\Modules\Identity\Models\ApiKey;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Contracts\HostResolver;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\WebhookSigner;
use App\Modules\Webhooks\Testing\FakeHostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | A3 · 28. kritérium — „eseménytípusonként KONFIGURÁLHATÓ endpoint".
 |
 | Ez a konfigurálhatóság: enélkül a `webhook_endpoints` tábla élesben örökre üres maradna,
 | és a teljes aláírt kézbesítési lánc holt kód lenne — soha senki nem kapna eseményt.
 */

beforeEach(function () {
    $this->resolver = new FakeHostResolver;
    $this->app->instance(HostResolver::class, $this->resolver);

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    ['plain' => $this->plain] = ApiKey::issue('CI', ['webhooks:write', 'logs:read']);
});

function endpointCall(string $method, string $uri, array $body = []): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->plain,
        'Accept' => 'application/json',
    ])->json($method, $uri, $body);
}

it('létrehoz egy végpontot, és a titok EGYSZER látszik', function () {
    $response = endpointCall('POST', '/v1/webhooks/endpoints', [
        'name' => 'CRM',
        'url' => 'https://hook.example.test/ventmail',
        'events' => ['delivered', 'bounced'],
    ]);

    $response->assertStatus(201);

    $secret = (string) $response->json('secret');

    expect($secret)->toStartWith('whsec_');
    expect($response->json('events'))->toBe(['delivered', 'bounced']);

    // A listában MÁR NEM: a titok csak az aláírásunkban él tovább.
    $list = endpointCall('GET', '/v1/webhooks/endpoints');

    expect($list->json('items.0.secret'))->toBeNull();
    expect(json_encode($list->json()))->not->toContain($secret);

    // …és attól még a VALÓDI titok, amivel aláírunk.
    $endpoint = WebhookEndpoint::query()->firstOrFail();

    expect($endpoint->signingSecret())->toBe($secret);
});

it('a titkot az ÜGYFÉL nem adhatja meg', function () {
    // Ha a `secret` tömegesen kitölthető lenne, a saját gyenge titkát tehetné be — és az
    // aláírásunk annyit érne, amennyit az ő „titka".
    endpointCall('POST', '/v1/webhooks/endpoints', [
        'name' => 'CRM',
        'url' => 'https://hook.example.test/ventmail',
        'secret' => 'whsec_gyenge',
    ])->assertStatus(201);

    expect(WebhookEndpoint::query()->firstOrFail()->signingSecret())->not->toBe('whsec_gyenge');
});

it('a belső címre mutató URL-t elutasítja — a BEÍRÁSKOR', function () {
    $response = endpointCall('POST', '/v1/webhooks/endpoints', [
        'name' => 'Metadata',
        'url' => 'https://[::ffff:169.254.169.254]/latest/meta-data/',
    ]);

    $response->assertStatus(400);

    expect($response->json('error.code'))->toBe('INVALID_WEBHOOK_URL');
    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('a MÓDOSÍTÁS sem viheti belső címre', function () {
    ['endpoint' => $endpoint] = WebhookEndpoint::issue('CRM', 'https://hook.example.test/w', []);

    $response = endpointCall('PATCH', '/v1/webhooks/endpoints/'.$endpoint->uuid, [
        'url' => 'https://127.0.0.1:5432/',
    ]);

    $response->assertStatus(400);

    expect($endpoint->fresh()->url)->toBe('https://hook.example.test/w');
});

it('az ismeretlen eseménytípust visszautasítja', function () {
    $response = endpointCall('POST', '/v1/webhooks/endpoints', [
        'name' => 'CRM',
        'url' => 'https://hook.example.test/w',
        'events' => ['delivered', 'nincs-ilyen'],
    ]);

    $response->assertStatus(400);

    expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
});

it('a titok-rotáció ÉRVÉNYTELENÍTI a régit', function () {
    $created = endpointCall('POST', '/v1/webhooks/endpoints', [
        'name' => 'CRM',
        'url' => 'https://hook.example.test/w',
    ]);

    $regi = (string) $created->json('secret');
    $uuid = (string) $created->json('id');

    $uj = (string) endpointCall('POST', '/v1/webhooks/endpoints/'.$uuid.'/rotate-secret')->json('secret');

    expect($uj)->not->toBe($regi);

    // Nincs átfedési ablak: a rotációt jellemzően szivárgás váltja ki.
    $signer = app(WebhookSigner::class);
    $now = now()->getTimestamp();
    $payload = '{"event":"delivered"}';

    $alairas = $signer->sign(WebhookEndpoint::query()->firstOrFail()->signingSecret(), $payload, $now);

    expect($signer->verify($uj, $payload, $now, $alairas))->toBeTrue();
    expect($signer->verify($regi, $payload, $now, $alairas))->toBeFalse();
});

it('a törölt végpont eltűnik a listából', function () {
    ['endpoint' => $endpoint] = WebhookEndpoint::issue('CRM', 'https://hook.example.test/w', []);

    endpointCall('DELETE', '/v1/webhooks/endpoints/'.$endpoint->uuid)->assertStatus(204);

    expect(endpointCall('GET', '/v1/webhooks/endpoints')->json('items'))->toBe([]);
});

it('IDEGEN workspace végpontja nem érhető el', function () {
    ['endpoint' => $endpoint] = WebhookEndpoint::issue('CRM', 'https://hook.example.test/w', []);

    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);

    ['plain' => $idegenKulcs] = ApiKey::issue('Idegen', ['webhooks:write', 'logs:read']);

    $valasz = test()->withHeaders([
        'Authorization' => 'Bearer '.$idegenKulcs,
        'Accept' => 'application/json',
    ])->json('PATCH', '/v1/webhooks/endpoints/'.$endpoint->uuid, ['name' => 'Eltérítve']);

    // 404, nem 400: az idegen végpont a hívó számára NEM LÉTEZIK. Ugyanaz a válasz, mint egy
    // sosem létezett azonosítóra — a különbség létezés-orákulum lenne.
    $valasz->assertStatus(404);

    expect($valasz->json('error.code'))->toBe('UNKNOWN_ENDPOINT');
    expect($endpoint->fresh()->name)->toBe('CRM');
});

it('webhooks:write scope nélkül nem lehet végpontot létrehozni', function () {
    ['plain' => $olvaso] = ApiKey::issue('Csak olvas', ['logs:read']);

    test()->withHeaders([
        'Authorization' => 'Bearer '.$olvaso,
        'Accept' => 'application/json',
    ])->json('POST', '/v1/webhooks/endpoints', [
        'name' => 'CRM',
        'url' => 'https://hook.example.test/w',
    ])->assertStatus(403);
});
