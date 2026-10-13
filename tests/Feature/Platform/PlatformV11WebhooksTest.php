<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Contracts\HostResolver;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\WebhookSigner;
use App\Modules\Webhooks\Testing\FakeHostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 | v1.1 · 7. modul — webhookok: titok-rotáció türelmi ablakkal (D171), teszt-esemény (D172),
 | eseménytípus-szerkesztés, auditált újraküldés.
 */

beforeEach(function () {
    app()->instance(HostResolver::class, new FakeHostResolver);

    $this->workspace = Workspace::factory()->create();

    $this->user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'wh-'.uniqid(),
        'name' => 'Fejlesztő',
        'email' => 'wh-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);
    $this->workspace->members()->create(['user_id' => $this->user->getKey(), 'roles' => ['developer']]);

    ['endpoint' => $this->endpoint, 'secret' => $this->secret] = app(WorkspaceContext::class)->runFor(
        $this->workspace,
        fn () => WebhookEndpoint::issue('CRM', 'https://ugyfel.test/hook', ['delivered']),
    );
});

function whAs(): mixed
{
    return test()->actingAs(test()->user)->withSession(['workspace_uuid' => test()->workspace->uuid]);
}

function deliverNow(WebhookDelivery $delivery): void
{
    (new DeliverWebhook((int) $delivery->workspace_id, (string) $delivery->uuid))
        ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));
}

it('a teszt-esemény levél NÉLKÜL, a valódi kézbesítő úton megy ki, aláírva és jelölve', function () {
    Http::fake(['https://ugyfel.test/*' => Http::response('ok', 200)]);

    whAs()->from('/webhooks')->post("/webhooks/{$this->endpoint->uuid}/test", ['event' => 'bounced'])
        ->assertRedirect('/webhooks')
        ->assertSessionHas('success');

    $delivery = WebhookDelivery::query()->withoutGlobalScopes()->firstOrFail();

    expect($delivery->is_test)->toBeTrue()
        ->and($delivery->event_type)->toBe('bounced')
        ->and($delivery->status)->toBe('delivered')
        ->and($delivery->payload['test'])->toBeTrue()
        ->and($delivery->payload['message']['recipient'])->toBe('recipient@example.com')
        ->and($delivery->duration_ms)->not->toBeNull();

    // Küldés NINCS: se üzenet, se esemény.
    expect(Message::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(MessageEvent::query()->withoutGlobalScopes()->count())->toBe(0);

    Http::assertSent(function (ClientRequest $request): bool {
        $timestamp = (int) $request->header('X-VentMail-Timestamp')[0];

        return $request->header('X-VentMail-Test')[0] === '1'
            && app(WebhookSigner::class)->verify($this->secret, $request->body(), $timestamp, $request->header('X-VentMail-Signature')[0]);
    });

    expect(AuditLog::query()->withoutGlobalScopes()->where('action', 'webhook.test_sent')->exists())->toBeTrue();
});

it('a teszt-esemény is az SSRF-őrön megy át: belső címre dead-letter, kérés nélkül', function () {
    Http::fake();

    // A végpont mentés-kori ellenőrzése után a DNS belső címre fordul (rebinding).
    $resolver = new FakeHostResolver;
    $resolver->map('ugyfel.test', ['10.0.0.5']);
    app()->instance(HostResolver::class, $resolver);

    whAs()->post("/webhooks/{$this->endpoint->uuid}/test", ['event' => 'delivered'])->assertRedirect();

    $delivery = WebhookDelivery::query()->withoutGlobalScopes()->firstOrFail();

    expect($delivery->status)->toBe('dead_letter');
    Http::assertNothingSent();
});

it('a teszt-esemény típusa zárt lista, percenként korlátozott, és idegen végpontra 404', function () {
    Queue::fake();

    whAs()->post("/webhooks/{$this->endpoint->uuid}/test", ['event' => 'accepted'])->assertSessionHasErrors('event');

    for ($i = 0; $i < 10; $i++) {
        whAs()->post("/webhooks/{$this->endpoint->uuid}/test", ['event' => 'opened'])->assertSessionHas('success');
    }

    whAs()->post("/webhooks/{$this->endpoint->uuid}/test", ['event' => 'opened'])->assertSessionHas('error');

    $idegen = Workspace::factory()->create();
    $masik = app(WorkspaceContext::class)->runFor($idegen, fn () => WebhookEndpoint::issue('X', 'https://masik.test/h', [])['endpoint']);

    whAs()->post("/webhooks/{$masik->uuid}/test", ['event' => 'opened'])->assertNotFound();
    whAs()->post("/webhooks/{$masik->uuid}/rotate-secret", ['grace' => true])->assertNotFound();
    whAs()->patch("/webhooks/{$masik->uuid}", ['events' => []])->assertNotFound();
});

it('türelmi ablakos rotációnál a régi titok a második fejlécben aláír, azonnalinál nem', function () {
    Http::fake(['https://ugyfel.test/*' => Http::response('', 200)]);

    whAs()->post("/webhooks/{$this->endpoint->uuid}/rotate-secret", ['grace' => true])->assertSessionHas('secret');
    $new = session('secret')['value'];

    expect($new)->toStartWith('whsec_')->not->toBe($this->secret);

    $endpoint = WebhookEndpoint::query()->withoutGlobalScopes()->find($this->endpoint->getKey());

    expect($endpoint->signingSecret())->toBe($new)
        ->and($endpoint->previousSigningSecret())->toBe($this->secret);

    $delivery = app(WorkspaceContext::class)->runFor($this->workspace, fn () => WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->getKey(),
        'event_type' => 'delivered',
        'payload' => ['event' => 'delivered'],
        'status' => 'pending',
    ]));

    deliverNow($delivery);

    Http::assertSent(function (ClientRequest $request) use ($new): bool {
        $ts = (int) $request->header('X-VentMail-Timestamp')[0];
        $signer = app(WebhookSigner::class);

        return $signer->verify($new, $request->body(), $ts, $request->header('X-VentMail-Signature')[0])
            && $signer->verify($this->secret, $request->body(), $ts, $request->header('X-VentMail-Signature-Previous')[0]);
    });

    // A türelmi ablak LEJÁR: utána a régi titok sehol.
    $this->travel(25)->hours();
    expect(WebhookEndpoint::query()->withoutGlobalScopes()->find($endpoint->getKey())->previousSigningSecret())->toBeNull();

    // Azonnali rotáció: nincs régi titok.
    whAs()->post("/webhooks/{$this->endpoint->uuid}/rotate-secret", ['grace' => false]);
    expect(WebhookEndpoint::query()->withoutGlobalScopes()->find($endpoint->getKey())->previousSigningSecret())->toBeNull()
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'webhook.secret_rotated')->count())->toBe(2);
});

it('a /v1 rotáció szerződése változatlan: azonnali csere, türelmi ablak nélkül', function () {
    ['plain' => $plain] = app(WorkspaceContext::class)->runFor($this->workspace, fn () => ApiKey::issue('CI', ['webhooks:write']));

    $this->withHeaders(['Authorization' => 'Bearer '.$plain])
        ->postJson("/v1/webhooks/endpoints/{$this->endpoint->uuid}/rotate-secret")
        ->assertOk()
        ->assertJsonStructure(['id', 'secret']);

    expect(WebhookEndpoint::query()->withoutGlobalScopes()->find($this->endpoint->getKey())->previousSigningSecret())->toBeNull();
});

it('az eseménytípus-szerkesztés zárt listából menthető és auditált; a titok nem kerül a válaszba', function () {
    whAs()->patch("/webhooks/{$this->endpoint->uuid}", ['events' => ['delivered', 'opened', 'clicked'], 'active' => false])
        ->assertSessionHas('success');

    $endpoint = WebhookEndpoint::query()->withoutGlobalScopes()->find($this->endpoint->getKey());

    expect($endpoint->events)->toBe(['delivered', 'opened', 'clicked'])
        ->and($endpoint->active)->toBeFalse();

    whAs()->patch("/webhooks/{$this->endpoint->uuid}", ['events' => ['nem-letezik']])->assertSessionHasErrors('events.0');

    whAs()->get('/webhooks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('endpoints.0.secretHint', substr($this->secret, -4))
            ->where('testEvents', ['delivered', 'opened', 'clicked', 'bounced', 'complained', 'unsubscribed'])
        )
        ->assertDontSee($this->secret);
});

it('a kézbesítési napló szűrhető, és az újraküldés auditált', function () {
    $delivery = app(WorkspaceContext::class)->runFor($this->workspace, fn () => WebhookDelivery::create([
        'webhook_endpoint_id' => $this->endpoint->getKey(),
        'event_type' => 'delivered',
        'payload' => ['event' => 'delivered'],
        'status' => 'dead_letter',
        'attempt' => 6,
        'dead_lettered_at' => now(),
    ]));

    whAs()->get('/webhooks?status=dead_letter')->assertInertia(fn ($page) => $page->has('deliveries', 1));
    whAs()->get('/webhooks?status=delivered')->assertInertia(fn ($page) => $page->has('deliveries', 0));

    whAs()->post("/webhooks/deliveries/{$delivery->uuid}/replay")->assertSessionHas('success');

    expect(WebhookDelivery::query()->withoutGlobalScopes()->find($delivery->getKey())->status)->toBe('failed')
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'webhook.replayed')->exists())->toBeTrue();
});

it('S4: az e2e-fogadó csak local/testing környezetben kapcsol be, élesben soha', function () {
    config()->set('webhooks.e2e_sink', 'https://93.184.216.34/e2e/');

    expect(\App\Modules\Webhooks\Testing\E2eWebhookSink::enabled())->toBeTrue();

    $original = app()->environment();
    app()['env'] = 'production';

    try {
        expect(\App\Modules\Webhooks\Testing\E2eWebhookSink::enabled())->toBeFalse();
    } finally {
        app()['env'] = $original;
    }

    config()->set('webhooks.e2e_sink', 'http://belso.test/');
    expect(\App\Modules\Webhooks\Testing\E2eWebhookSink::enabled())->toBeFalse();
});

it('RS5: inaktív végpontra nem küldhető teszt-esemény (validációs hiba, nincs örök pending sor)', function () {
    $this->endpoint->forceFill(['active' => false])->save();

    whAs()->from('/webhooks')->post("/webhooks/{$this->endpoint->uuid}/test", ['event' => 'delivered'])
        ->assertSessionHasErrors('event');

    expect(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);
});
