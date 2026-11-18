<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Events\Http\Controllers\EventsController;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Contracts\HostResolver;
use App\Modules\Webhooks\Http\Controllers\WebhookEndpointController;
use App\Modules\Webhooks\Http\Controllers\WebhookUiController;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\WebhookSigner;
use App\Modules\Webhooks\Testing\FakeHostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

/*
 | Kézbesítési-esemény webhookok az integrációknak (Jersey Magic: „melyik tranzakciós levelem
 | pattant vissza?").
 |
 | A fogadó a saját rekordját a `message.id`-vel találja meg (ez a `POST /v1/messages` válaszának
 | `id`-je), az eseményt a STABIL `id`-vel dedupolja (újrapróbálás és replay alatt sem változik),
 | és a címzett címét nem kell megkapnia: a maszkolt alak elég a személyzeti felülethez.
 */

beforeEach(function () {
    Queue::fake();
    app()->instance(HostResolver::class, new FakeHostResolver);

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    ['endpoint' => $this->endpoint, 'secret' => $this->secret] = WebhookEndpoint::issue('JM', 'https://jm.example.test/hooks/ventmail', []);
});

function deliveryEvent(string $type, array $attributes = []): MessageEvent
{
    return MessageEvent::create(array_merge([
        'message_uuid' => (string) Str::uuid(),
        'type' => $type,
        'recipient' => 'anna.kovacs@example.test',
        'channel' => 'transactional',
        'tags' => ['delivery'],
        'variables' => [],
    ], $attributes));
}

function deliverEventNow(WebhookDelivery $delivery): void
{
    (new DeliverWebhook((int) $delivery->workspace_id, (string) $delivery->uuid))
        ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));

    app(WorkspaceContext::class)->set(test()->workspace);
}

function hookApi(string $method, string $uri, array $body = []): TestResponse
{
    ['plain' => $plain] = ApiKey::issue('CI', ['webhooks:write', 'logs:read', 'events:read']);

    return test()->withHeaders(['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'])->json($method, $uri, $body);
}

it('a payload STABIL esemény-azonosítót hordoz — végpontonként és replay után is ugyanazt', function () {
    WebhookEndpoint::issue('Másik', 'https://other.example.test/hook', []);

    $event = deliveryEvent('bounced', ['reason' => 'hard', 'payload' => ['code' => 550, 'enhanced_code' => '5.1.1', 'classification' => 'hard']]);

    $deliveries = WebhookDelivery::query()->orderBy('id')->get();

    expect($deliveries)->toHaveCount(2);
    foreach ($deliveries as $delivery) {
        expect($delivery->payload['id'])->toBe((string) $event->uuid);
    }

    Http::fake(['https://jm.example.test/*' => Http::sequence()->push('', 500)->push('', 200)]);

    $first = $deliveries->first();
    deliverEventNow($first);
    deliverEventNow($first->fresh());

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->header('X-VentMail-Event-Id')[0] === (string) $event->uuid
        && json_decode($request->body(), true)['id'] === (string) $event->uuid);
});

it('a payload a maszkolt címzettet is hordozza, és a bounce típusát (hard / soft)', function () {
    deliveryEvent('bounced', ['reason' => 'hard', 'payload' => ['code' => 550, 'enhanced_code' => '5.1.1', 'classification' => 'hard']]);
    deliveryEvent('bounced', ['reason' => 'mailbox_full', 'payload' => ['code' => 552, 'enhanced_code' => '5.2.2', 'classification' => 'mailbox_full']]);
    deliveryEvent('delivered');

    $payloads = WebhookDelivery::query()->orderBy('id')->pluck('payload');

    expect($payloads[0]['message']['recipient_masked'])->toBe('a***@example.test');
    expect($payloads[0]['message']['recipient'])->toBe('anna.kovacs@example.test');
    expect($payloads[0]['delivery']['bounce_type'])->toBe('hard');
    expect($payloads[1]['delivery']['bounce_type'])->toBe('soft');
    expect($payloads[2]['delivery']['bounce_type'])->toBeNull();
});

it('redact_recipient végpontra a NYERS cím sehol nem kerül — a tárolt kézbesítésben sem', function () {
    $this->endpoint->forceFill(['redact_recipient' => true])->save();

    deliveryEvent('bounced', ['reason' => 'hard', 'payload' => ['code' => 550, 'classification' => 'hard']]);

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->payload['message']['recipient'])->toBeNull();
    expect($delivery->payload['message']['recipient_masked'])->toBe('a***@example.test');
    expect(json_encode($delivery->payload))->not->toContain('anna.kovacs');
});

it('a /v1 végpont-API kezeli a redact_recipient kapcsolót, és a failed eseménytípust', function () {
    $created = hookApi('POST', '/v1/webhooks/endpoints', [
        'name' => 'Jersey Magic',
        'url' => 'https://jm2.example.test/hook',
        'events' => ['bounced', 'complained', 'failed'],
        'redact_recipient' => true,
    ])->assertStatus(201);

    expect($created->json('redact_recipient'))->toBeTrue();
    expect($created->json('events'))->toBe(['bounced', 'complained', 'failed']);

    $id = (string) $created->json('id');

    hookApi('PATCH', "/v1/webhooks/endpoints/{$id}", ['redact_recipient' => false])
        ->assertOk()->assertJsonPath('redact_recipient', false);

    // Alapértelmezés: a meglévő szerződés változatlan (a nyers cím megy).
    expect(hookApi('POST', '/v1/webhooks/endpoints', ['name' => 'B', 'url' => 'https://b.example.test/h'])->json('redact_recipient'))->toBeFalse();

    hookApi('GET', '/v1/events?type=failed')->assertOk();
});

it('a /v1 replay auditált', function () {
    deliveryEvent('bounced', ['reason' => 'hard']);
    $delivery = WebhookDelivery::query()->firstOrFail();
    $delivery->forceFill(['status' => 'dead_letter'])->save();

    hookApi('POST', "/v1/webhooks/deliveries/{$delivery->uuid}/replay")->assertStatus(202);

    $audit = AuditLog::query()->where('action', 'webhook.replayed')->firstOrFail();

    expect($audit->context['api_key'] ?? null)->toBeString()->not->toBe('');
});

it('a teszt-esemény payloadja is a valódi alakot követi (id, maszkolt címzett, bounce_type)', function () {
    $sample = WebhookUiController::samplePayload('bounced');

    expect($sample['id'])->toBeString()->not->toBe('');
    expect($sample['message']['recipient_masked'])->toBe('r***@example.com');
    expect($sample['delivery']['bounce_type'])->toBe('hard');
    expect(WebhookUiController::samplePayload('bounced', true)['message']['recipient'])->toBeNull();
});

/*
 | KAT-vektorok a FOGYASZTÓNAK: a dokumentáció (OpenAPI `x-webhook-signature`) és a fejlesztői
 | útmutató ugyanazokat a vektorokat mutatja, amiket itt a futó aláíró ellenőriz. Ha a kettő
 | elmozdul, a fogadó oldal (pl. Jersey Magic) a doksiból hibás ellenőrzőt írna.
 */
it('a dokumentált KAT-vektorok bájtra egyeznek a futó aláíróval', function () {
    $spec = Yaml::parseFile(base_path('docs/openapi/ventmail-v1.yaml'));
    $vectors = $spec['x-webhook-signature']['kat'] ?? [];

    expect($vectors)->not->toBeEmpty();

    $guides = Yaml::parseFile(base_path('docs/developer/guides.yaml'));
    $webhookGuide = collect($guides['guides'] ?? $guides)->firstWhere('key', 'webhooks');

    foreach ($vectors as $vector) {
        $signature = app(WebhookSigner::class)->sign($vector['secret'], $vector['body'], (int) $vector['timestamp']);

        expect($signature)->toBe($vector['signature']);
        expect(hash_hmac('sha256', $vector['timestamp'].'.'.$vector['body'], $vector['secret']))->toBe($vector['signature']);

        foreach ($webhookGuide['body'] as $lang => $body) {
            expect(str_contains($body, $vector['signature']))->toBeTrue("a(z) {$lang} útmutatóból hiányzik a KAT-vektor");
        }
    }
});

it('a redact_recipient bekapcsolása után a MÁR függő kézbesítés újrapróbálása / replay-e sem küldi a nyers címet', function () {
    deliveryEvent('bounced', ['reason' => 'hard', 'payload' => ['code' => 550, 'classification' => 'hard']]);

    $delivery = WebhookDelivery::query()->firstOrFail();
    expect($delivery->payload['message']['recipient'])->toBe('anna.kovacs@example.test');

    $this->endpoint->forceFill(['redact_recipient' => true])->save();

    Http::fake(['https://jm.example.test/*' => Http::response('', 200)]);

    deliverEventNow($delivery);

    Http::assertSent(fn ($request) => ! str_contains($request->body(), 'anna.kovacs')
        && json_decode($request->body(), true)['message']['recipient_masked'] === 'a***@example.test');

    // A tárolt sorból is eltűnt.
    expect(json_encode($delivery->fresh()->payload))->not->toContain('anna.kovacs');
});

it('a GDPR-törlés a redaktált végpont kézbesítési sorát is megtalálja (message.id + maszkolt cím), a másik címzettét nem', function () {
    $this->endpoint->forceFill(['redact_recipient' => true])->save();

    $messageUuid = (string) Str::uuid();
    deliveryEvent('bounced', ['message_uuid' => $messageUuid, 'reason' => 'hard']);
    deliveryEvent('delivered', ['message_uuid' => $messageUuid, 'recipient' => 'bela@example.test']);

    $this->artisan('logs:redact-subject', ['email' => 'anna.kovacs@example.test'])->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    $rows = WebhookDelivery::query()->orderBy('id')->get();

    expect($rows[0]->payload)->toBe([]);
    expect($rows[1]->payload['message']['recipient_masked'])->toBe('b***@example.test');
});

it('az eseménytípus-listák (/v1/events, /v1 végpont, felület) pontosan az OpenAPI EventType enumja', function () {
    $documented = Yaml::parseFile(base_path('docs/openapi/ventmail-v1.yaml'))['components']['schemas']['EventType']['enum'];

    $read = fn (string $class) => (new ReflectionClassConstant($class, 'TYPES'))->getValue();

    expect($read(EventsController::class))->toBe($documented);
    expect($read(WebhookEndpointController::class))->toBe($documented);
    // A felület a belső `journey.step`-pel bővül, mást nem tartalmaz.
    expect(array_values(array_diff($read(WebhookUiController::class), ['journey.step'])))->toBe($documented);
    expect(WebhookUiController::TEST_TYPES)->toContain('failed');
});
