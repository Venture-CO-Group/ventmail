<?php

declare(strict_types=1);

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | A3 · 28. kritérium — webhook: HMAC-SHA256 + timestamp, retry, dead-letter, replay.
 */

beforeEach(function () {
    // A sort MI hajtjuk a tesztben: így látszik, mi történik EGY kísérletnél, és a
    // dead-letter felé vezető út is lépésről lépésre mérhető.
    Queue::fake();

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    ['endpoint' => $this->endpoint, 'secret' => $this->secret] = WebhookEndpoint::issue(
        'Integráció',
        'https://ugyfel.test/webhook',
        []
    );
});

function raiseEvent(string $type = 'delivered', array $attributes = []): MessageEvent
{
    return MessageEvent::create(array_merge([
        'message_uuid' => (string) Str::uuid(),
        'type' => $type,
        'recipient' => 'cimzett@example.test',
        'channel' => 'transactional',
        'tags' => ['szamla'],
        'variables' => ['rendeles' => 'A-42'],
    ], $attributes));
}

it('minden esemény kézbesítési sort kap a feliratkozott végponton', function () {
    raiseEvent();

    $delivery = WebhookDelivery::firstOrFail();

    expect($delivery->event_type)->toBe('delivered');
    expect($delivery->status)->toBe('pending');
    expect($delivery->payload['variables']['rendeles'])->toBe('A-42');
});

it('a végpont szűrhet eseménytípusra', function () {
    $this->endpoint->forceFill(['events' => ['bounced']])->save();

    raiseEvent('delivered');

    expect(WebhookDelivery::count())->toBe(0);

    raiseEvent('bounced');

    expect(WebhookDelivery::count())->toBe(1);
});

it('a kiküldött kérés ALÁÍRT, és az aláírás az IDŐBÉLYEGET is fedi', function () {
    Http::fake(['https://ugyfel.test/*' => Http::response('', 200)]);

    raiseEvent();

    $delivery = WebhookDelivery::firstOrFail();

    (new DeliverWebhook((int) $this->workspace->getKey(), (string) $delivery->uuid))
        ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));

    app(WorkspaceContext::class)->set($this->workspace);

    Http::assertSent(function ($request) {
        $timestamp = (int) $request->header('X-VentMail-Timestamp')[0];
        $signature = $request->header('X-VentMail-Signature')[0];

        // A FOGADÓ oldal ellenőrzése: a payload és az időbélyeg együtt van aláírva.
        return app(WebhookSigner::class)->verify(test()->secret, $request->body(), $timestamp, $signature);
    });

    expect(WebhookDelivery::firstOrFail()->status)->toBe('delivered');
});

it('a HAMIS aláírás nem verifikálódik', function () {
    $signer = app(WebhookSigner::class);
    $now = now()->getTimestamp();

    expect($signer->verify($this->secret, '{"a":1}', $now, str_repeat('0', 64)))->toBeFalse();
    // Más titok → más aláírás.
    expect($signer->verify('masik-titok', '{"a":1}', $now, $signer->sign($this->secret, '{"a":1}', $now)))->toBeFalse();
});

it('a RÉGI aláírás elutasítva — replay-védelem', function () {
    // Aki egyszer elkapta a kérést (proxy-log, félrekonfigurált végpont), az különben
    // évekig újraküldhetné ugyanazt a „hiteles" eseményt.
    $signer = app(WebhookSigner::class);
    $regi = now()->subHour()->getTimestamp();

    $signature = $signer->sign($this->secret, '{"a":1}', $regi);

    expect($signer->verify($this->secret, '{"a":1}', $regi, $signature))->toBeFalse();
});

it('a hibás válasz ÚJRAPRÓBÁLÁST ütemez, nem dob el', function () {
    Http::fake(['https://ugyfel.test/*' => Http::response('szerver-hiba', 500)]);

    raiseEvent();

    $delivery = WebhookDelivery::firstOrFail();

    (new DeliverWebhook((int) $this->workspace->getKey(), (string) $delivery->uuid))
        ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));

    app(WorkspaceContext::class)->set($this->workspace);

    $fresh = WebhookDelivery::firstOrFail();

    expect($fresh->status)->toBe('failed');
    expect($fresh->attempt)->toBe(1);
    expect($fresh->next_attempt_at)->not->toBeNull();
    expect($fresh->response_code)->toBe(500);
});

it('N hiba után DEAD-LETTER lesz — nem tűnik el, hanem láthatóvá válik', function () {
    Http::fake(['https://ugyfel.test/*' => Http::response('', 500)]);

    raiseEvent();

    $delivery = WebhookDelivery::firstOrFail();

    foreach (range(1, 6) as $attempt) {
        (new DeliverWebhook((int) $this->workspace->getKey(), (string) $delivery->uuid))
            ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));

        app(WorkspaceContext::class)->set($this->workspace);
    }

    $fresh = WebhookDelivery::firstOrFail();

    expect($fresh->status)->toBe('dead_letter');
    expect($fresh->dead_lettered_at)->not->toBeNull();
    expect($fresh->next_attempt_at)->toBeNull();
});

it('a DLQ-ból az újrajátszás kivezeti a sort', function () {
    ['plain' => $plain] = ApiKey::issue('CI', ['logs:read', 'webhooks:write']);

    raiseEvent();

    $delivery = WebhookDelivery::firstOrFail();

    // A sor a dead-letterben áll (hatszor hibázott a végpont)…
    $delivery->forceFill(['status' => 'dead_letter', 'attempt' => 6, 'dead_lettered_at' => now()])->save();

    // …az ügyfél megjavítja a végpontot.
    Http::fake(['https://ugyfel.test/*' => Http::response('', 200)]);

    $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'])
        ->postJson("/v1/webhooks/deliveries/{$delivery->uuid}/replay")
        ->assertStatus(202);

    app(WorkspaceContext::class)->set($this->workspace);

    // …a sorba tett job pedig kézbesít.
    (new DeliverWebhook((int) $this->workspace->getKey(), (string) $delivery->uuid))
        ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));

    app(WorkspaceContext::class)->set($this->workspace);

    expect(WebhookDelivery::firstOrFail()->status)->toBe('delivered');
});

it('az esedékes újrapróbálásokat a parancs sorba teszi', function () {
    Http::fake(['https://ugyfel.test/*' => Http::response('', 500)]);

    raiseEvent();

    WebhookDelivery::query()->update([
        'status' => 'failed',
        'next_attempt_at' => now()->subMinute(),
    ]);

    Queue::fake();

    $this->artisan('webhooks:retry')->assertSuccessful();

    Queue::assertPushed(DeliverWebhook::class);
});

it('a payload NEM tartalmaz levéltörzset', function () {
    // A payload HARMADIK FÉLHEZ megy ki: a kirenderelt törzs magic-linket és tokent hordoz.
    raiseEvent('delivered', ['payload' => ['body_sha256' => str_repeat('a', 64)]]);

    $payload = json_encode(WebhookDelivery::firstOrFail()->payload);

    expect(str_contains((string) $payload, 'body_sha256'))->toBeFalse();
    expect(WebhookDelivery::firstOrFail()->payload['message']['recipient'])->toBe('cimzett@example.test');
});

it('a titok TITKOSÍTVA van, és nem jelenik meg a modell tömbjében', function () {
    // Adatbázis-szivárgás önmagában nem elég ahhoz, hogy valaki érvényes aláírást gyártson
    // a nevünkben: a titkosítás az APP_KEY-hez köti.
    $raw = DB::table('webhook_endpoints')->value('secret');

    expect($raw)->not->toBe($this->secret);
    expect($this->endpoint->signingSecret())->toBe($this->secret);
    expect($this->endpoint->toArray())->not->toHaveKey('secret');
});
