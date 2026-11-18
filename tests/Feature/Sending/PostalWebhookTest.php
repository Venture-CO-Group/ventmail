<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Drivers\PostalDriver;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Models\DeliveryAttempt;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\PostalMessageRef;
use App\Modules\Sending\Models\PostalWebhookReceipt;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | A Postal → ventMail visszacsatolás (deploy-ovh 2. pont, éles ellenőrzés 6.).
 |
 | A Postal a kézbesítés kimenetelét ALÁÍRT webhookkal jelzi (RSA-SHA256, base64, az
 | `X-Postal-Signature-256` fejlécben). Enélkül a bounce SOHA nem jutna a suppression-táblába:
 | a ventMail ugyanarra a halott címre küldene tovább, és a reputáció ebbe hal bele.
 |
 | A két pool KÉT külön Postal-példány, külön aláíró kulccsal: az egyik példány kulcsa a
 | másik útvonalán nem érvényes.
 */

function postalKeypair(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);

    return [$private, openssl_pkey_get_details($key)['key']];
}

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    [$this->txPrivate, $txPublic] = postalKeypair();
    [$this->mkPrivate, $mkPublic] = postalKeypair();

    config([
        'sending.postal.pools.transactional.webhook_public_key' => $txPublic,
        'sending.postal.pools.marketing.webhook_public_key' => $mkPublic,
    ]);

    $this->message = Message::create([
        'channel' => 'transactional',
        'from_email' => 'noreply@pelda.hu',
        'subject' => 'Teszt',
        'recipients' => ['halott@example.test'],
        'status' => 'sent',
        'options' => [],
    ]);

    PostalMessageRef::create([
        'token' => 'tok-1',
        'pool' => 'transactional',
        'message_uuid' => $this->message->uuid,
        'recipient' => 'halott@example.test',
    ]);

    // A webhook NEM a felhasználó kontextusában jön: a kontextust a hivatkozásból kell
    // helyreállítani, nem örökölni.
    app(WorkspaceContext::class)->forget();
});

function postalBody(string $event, array $payload, ?string $uuid = null): string
{
    return json_encode([
        'event' => $event,
        'timestamp' => microtime(true),
        'payload' => $payload,
        'uuid' => $uuid ?? (string) Str::uuid(),
    ], JSON_THROW_ON_ERROR);
}

function postalMessage(string $token = 'tok-1'): array
{
    return [
        'id' => 1, 'token' => $token, 'direction' => 'outgoing', 'message_id' => 'x@rp-tx',
        'to' => 'halott@example.test', 'from' => 'noreply@pelda.hu', 'subject' => 'Teszt',
        'timestamp' => microtime(true), 'spam_status' => 'NotChecked', 'tag' => null,
    ];
}

function postWebhook(string $pool, string $body, string $privateKey): TestResponse
{
    openssl_sign($body, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    return test()->call('POST', "/internal/mta/postal/{$pool}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_POSTAL_SIGNATURE_256' => base64_encode($signature),
    ], $body);
}

it('a hard fail suppression-listára teszi a címet', function () {
    $body = postalBody('MessageDeliveryFailed', [
        'message' => postalMessage(), 'status' => 'HardFail', 'details' => 'Permanent',
        'output' => '550 5.1.1 The email account that you tried to reach does not exist',
        'sent_with_ssl' => true, 'timestamp' => microtime(true), 'time' => 0.3,
    ]);

    postWebhook('transactional', $body, $this->txPrivate)->assertNoContent();

    expect(Suppression::onPlatform(fn () => Suppression::query()->withoutGlobalScopes()
        ->where('email', 'halott@example.test')->where('workspace_id', $this->workspace->id)->exists()))->toBeTrue();
    expect(DeliveryAttempt::query()->withoutGlobalScopes()->where('message_uuid', $this->message->uuid)->value('code'))->toBe(550);
});

it('az aszinkron bounce (DSN) is suppression-listára tesz', function () {
    $body = postalBody('MessageBounced', [
        'original_message' => postalMessage(),
        'bounce' => postalMessage('bounce-tok') + ['direction' => 'incoming'],
    ]);

    postWebhook('transactional', $body, $this->txPrivate)->assertNoContent();

    expect(Suppression::query()->withoutGlobalScopes()->where('email', 'halott@example.test')->exists())->toBeTrue();
});

it('a bounce után a következő küldést a PreSendGate elutasítja', function () {
    postWebhook('transactional', postalBody('MessageBounced', [
        'original_message' => postalMessage(),
        'bounce' => postalMessage('bounce-tok'),
    ]), $this->txPrivate)->assertNoContent();

    app(WorkspaceContext::class)->set($this->workspace);
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
    RateLimiter::clear('sending:'.$this->workspace->id);

    $decision = app(SendMessage::class)([
        'channel' => 'transactional', 'from_email' => 'noreply@pelda.hu',
        'to' => 'halott@example.test', 'subject' => 'Újra', 'html' => '<p>x</p>',
    ])->decision;

    expect($decision->allowed)->toBeFalse();
    expect($decision->reason)->toBe(GateReason::Suppressed->value);
});

it('a sikeres kézbesítés delivered eseményt ír', function () {
    postWebhook('transactional', postalBody('MessageSent', [
        'message' => postalMessage(), 'status' => 'Sent', 'details' => 'Message for halott@example.test accepted',
        'output' => '250 2.0.0 OK', 'sent_with_ssl' => true, 'timestamp' => microtime(true), 'time' => 0.2,
    ]), $this->txPrivate)->assertNoContent();

    expect(MessageEvent::query()->withoutGlobalScopes()
        ->where('message_uuid', $this->message->uuid)->where('type', 'delivered')
        ->where('recipient', 'halott@example.test')->exists())->toBeTrue();
    expect(Suppression::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('aláírás nélkül vagy hamis aláírással elutasít', function () {
    $body = postalBody('MessageBounced', ['original_message' => postalMessage(), 'bounce' => postalMessage('b')]);

    $this->call('POST', '/internal/mta/postal/transactional', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
        ->assertUnauthorized();
    [$foreign] = postalKeypair();
    postWebhook('transactional', $body, $foreign)->assertUnauthorized();

    expect(Suppression::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('a marketing példány kulcsa a tranzakciós útvonalon nem érvényes', function () {
    $body = postalBody('MessageBounced', ['original_message' => postalMessage(), 'bounce' => postalMessage('b')]);

    postWebhook('transactional', $body, $this->mkPrivate)->assertUnauthorized();

    expect(Suppression::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('a másik pool hivatkozására érkező esemény nem hat', function () {
    // Érvényes marketing-aláírás, de a token a TRANZAKCIÓS példányé.
    $body = postalBody('MessageBounced', ['original_message' => postalMessage(), 'bounce' => postalMessage('b')]);

    postWebhook('marketing', $body, $this->mkPrivate)->assertNoContent();

    expect(Suppression::query()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('beállított kulcs nélkül fail-closed', function () {
    config(['sending.postal.pools.transactional.webhook_public_key' => '']);

    postWebhook('transactional', postalBody('MessageSent', ['message' => postalMessage()]), $this->txPrivate)
        ->assertStatus(503);
});

it('ismeretlen pool 404', function () {
    postWebhook('egyeb', postalBody('MessageSent', ['message' => postalMessage()]), $this->txPrivate)
        ->assertNotFound();
});

it('az ismételt kézbesítés (ugyanaz az uuid) egyszer hat', function () {
    $body = postalBody('MessageDeliveryFailed', [
        'message' => postalMessage(), 'status' => 'HardFail', 'details' => 'x',
        'output' => '550 5.1.1 no such user', 'sent_with_ssl' => true, 'timestamp' => microtime(true), 'time' => 0.1,
    ], 'ugyanaz-az-uuid');

    postWebhook('transactional', $body, $this->txPrivate)->assertNoContent();
    postWebhook('transactional', $body, $this->txPrivate)->assertNoContent();

    expect(DeliveryAttempt::query()->withoutGlobalScopes()->where('message_uuid', $this->message->uuid)->count())->toBe(1);
});

it('az ismeretlen token nem hiba — a Postal ne próbálkozzon újra a végtelenségig', function () {
    postWebhook('transactional', postalBody('MessageSent', ['message' => postalMessage('ismeretlen')]), $this->txPrivate)
        ->assertNoContent();
});

it('a PostalDriver címzettenként eltárolja a Postal-tokent a pool-lal', function () {
    app(WorkspaceContext::class)->set($this->workspace);
    config(['sending.postal.pools.transactional.endpoint' => 'http://postal-tx:5000', 'sending.postal.pools.transactional.api_key' => 'k']);
    Http::fake(['postal-tx:5000/*' => Http::response(['status' => 'success', 'data' => [
        'message_id' => 'm@rp', 'messages' => ['uj@example.test' => ['id' => 9, 'token' => 'tok-uj']],
    ]])]);

    $message = Message::create([
        'channel' => 'transactional', 'from_email' => 'noreply@pelda.hu', 'subject' => 'x',
        'recipients' => ['uj@example.test'], 'options' => [],
    ]);

    (new PostalDriver)->send($message->withRenderedBodies(['uj@example.test' => ['html' => '<p>x</p>', 'text' => 'x']]));

    $ref = PostalMessageRef::query()->where('token', 'tok-uj')->firstOrFail();
    expect($ref->workspace_id)->toBe($this->workspace->id);
    expect($ref->pool)->toBe('transactional');
    expect($ref->message_uuid)->toBe($message->uuid);
    expect($ref->recipient)->toBe('uj@example.test');
});

it('a logs:purge a lejárt Postal-hivatkozást és webhook-nyugtát is törli (golden rule 10)', function () {
    config(['sending.delivery_attempt_retention_days' => 90]);
    app(WorkspaceContext::class)->set($this->workspace);

    PostalMessageRef::query()->where('token', 'tok-1')->update(['created_at' => now()->subDays(91)]);
    PostalMessageRef::create([
        'token' => 'friss', 'pool' => 'transactional', 'message_uuid' => $this->message->uuid,
        'recipient' => 'halott@example.test',
    ]);
    PostalWebhookReceipt::create(['pool' => 'transactional', 'uuid' => 'regi', 'event' => 'MessageSent', 'received_at' => now()->subDays(91)]);
    PostalWebhookReceipt::create(['pool' => 'transactional', 'uuid' => 'uj', 'event' => 'MessageSent', 'received_at' => now()]);

    $this->artisan('logs:purge')->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);
    expect(PostalMessageRef::query()->pluck('token')->all())->toBe(['friss']);
    expect(PostalWebhookReceipt::query()->pluck('uuid')->all())->toBe(['uj']);
});

it('a hivatkozás tenant-izolált: idegen workspace kontextusából nem látszik', function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());

    expect(PostalMessageRef::query()->where('token', 'tok-1')->exists())->toBeFalse();
    // A webhook-feloldás (kontextus nélküli, pontos egyezés) viszont megtalálja — és a
    // hivatkozás SAJÁT workspace-ét adja vissza.
    expect(PostalMessageRef::resolve('transactional', 'tok-1')?->workspace_id)->toBe($this->workspace->id);
    expect(PostalMessageRef::resolve('marketing', 'tok-1'))->toBeNull();
});

it('a késleltetés (MessageDelayed) NEM számít soft bounce-nak — a greylist nem tilt le címet', function () {
    foreach (range(1, 7) as $i) {
        postWebhook('transactional', postalBody('MessageDelayed', [
            'message' => postalMessage(), 'status' => 'SoftFail', 'details' => 'greylisted',
            'output' => '451 4.7.1 Greylisting in action, please come back later', 'sent_with_ssl' => true,
            'timestamp' => microtime(true), 'time' => 0.1,
        ]), $this->txPrivate)->assertNoContent();
    }

    expect(Suppression::query()->withoutGlobalScopes()->exists())->toBeFalse()
        ->and(DeliveryAttempt::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(MessageEvent::query()->withoutGlobalScopes()->where('type', 'deferred')->count())->toBe(7);
});

it('élesben a Postal belső hostneve (ventmail-web) is kiszolgált', function () {
    config(['proxy.trusted_hosts' => ['app.ventmail.app', 'api.ventmail.app', 'ventmail-web']]);
    app()->detectEnvironment(fn () => 'production');

    $body = postalBody('MessageSent', ['message' => postalMessage(), 'status' => 'Sent', 'output' => '250 OK', 'sent_with_ssl' => true]);
    openssl_sign($body, $signature, $this->txPrivate, OPENSSL_ALGO_SHA256);

    $this->call('POST', 'http://ventmail-web:8080/internal/mta/postal/transactional', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_POSTAL_SIGNATURE_256' => base64_encode($signature),
    ], $body)->assertNoContent();
});

it('többcímzettes üzenetnél egy címzett bounce-a nem jelöli bounced-nak az egészet', function () {
    app(WorkspaceContext::class)->set($this->workspace);
    $this->message->forceFill(['recipients' => ['halott@example.test', 'elo@example.test']])->save();
    app(WorkspaceContext::class)->forget();

    postWebhook('transactional', postalBody('MessageBounced', [
        'original_message' => postalMessage(), 'bounce' => postalMessage('b'),
    ]), $this->txPrivate)->assertNoContent();

    expect(Message::query()->withoutGlobalScopes()->find($this->message->id)->status)->toBe('sent');
});

it('a Postal-eseményekre a webhook megkapja az üzenet v: változóit (a hívó így korrelál)', function () {
    $this->message->forceFill(['variables' => ['notification_id' => '01J0000000000000000000000A', 'recipient-variables' => ['x' => 1]]])->save();

    postWebhook('transactional', postalBody('MessageSent', [
        'message' => postalMessage(), 'status' => 'Sent', 'details' => 'ok',
        'output' => '250 2.0.0 OK', 'sent_with_ssl' => true, 'timestamp' => microtime(true), 'time' => 0.2,
    ]), $this->txPrivate)->assertNoContent();

    postWebhook('transactional', postalBody('MessageBounced', [
        'original_message' => postalMessage(),
        'bounce' => postalMessage('bounce-tok') + ['direction' => 'incoming'],
    ]), $this->txPrivate)->assertNoContent();

    $events = MessageEvent::query()->withoutGlobalScopes()->where('message_uuid', $this->message->uuid)
        ->whereIn('type', ['delivered', 'bounced'])->get();

    expect($events)->toHaveCount(2);
    foreach ($events as $event) {
        expect($event->variables)->toBe(['notification_id' => '01J0000000000000000000000A']);
    }
});

it('a VÉGLEGES kudarc ideiglenes kóddal (a Postal feladta) failed eseményt ír — a fogadó app ebből tudja, hogy nem ért célba', function () {
    postWebhook('transactional', postalBody('MessageDeliveryFailed', [
        'message' => postalMessage(), 'status' => 'SoftFail', 'details' => 'Gave up after retries',
        'output' => '451 4.4.1 Connection timed out', 'sent_with_ssl' => false, 'timestamp' => microtime(true), 'time' => 30.0,
    ]), $this->txPrivate)->assertNoContent();

    $failed = MessageEvent::query()->withoutGlobalScopes()
        ->where('message_uuid', $this->message->uuid)->where('type', 'failed')->first();

    expect($failed)->not->toBeNull();
    expect($failed->recipient)->toBe('halott@example.test');
    expect($failed->payload['code'])->toBe(451);
});

it('a hard fail bounced eseményt ír, failed-et NEM (a kettő nem duplázódik)', function () {
    postWebhook('transactional', postalBody('MessageDeliveryFailed', [
        'message' => postalMessage(), 'status' => 'HardFail', 'details' => 'Permanent',
        'output' => '550 5.1.1 No such user', 'sent_with_ssl' => true, 'timestamp' => microtime(true), 'time' => 0.3,
    ]), $this->txPrivate)->assertNoContent();

    $types = MessageEvent::query()->withoutGlobalScopes()->where('message_uuid', $this->message->uuid)->pluck('type')->all();

    expect($types)->toContain('bounced')->not->toContain('failed');
});

it('SMTP-kód nélküli (időtúllépés) végleges kudarc failed — NEM hard bounce, nincs suppression', function () {
    postWebhook('transactional', postalBody('MessageDeliveryFailed', [
        'message' => postalMessage(), 'status' => 'SoftFail', 'details' => 'Gave up',
        'output' => 'Connection timed out', 'sent_with_ssl' => false, 'timestamp' => microtime(true), 'time' => 30.0,
    ]), $this->txPrivate)->assertNoContent();

    $types = MessageEvent::query()->withoutGlobalScopes()->where('message_uuid', $this->message->uuid)->pluck('type')->all();

    expect($types)->toContain('failed')->not->toContain('bounced');
    expect(Suppression::query()->withoutGlobalScopes()->where('email', 'halott@example.test')->exists())->toBeFalse();
});
