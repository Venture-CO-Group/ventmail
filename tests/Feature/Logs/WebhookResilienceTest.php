<?php

declare(strict_types=1);

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Contracts\HostResolver;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\WebhookDispatcher;
use App\Modules\Webhooks\Services\WebhookSigner;
use App\Modules\Webhooks\Testing\FakeHostResolver;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | A3 · 28. kritérium — a retry-lánc AKKOR is tart, ha valami közbejön.
 |
 | A „nem tűnik el, hanem láthatóvá válik" ígéret csak akkor igaz, ha minden állapotból van
 | kivezető út: a félbeszakadt job, az elmozdult APP_KEY és a DNS-kiesés is.
 */

beforeEach(function () {
    $this->app->instance(HostResolver::class, new FakeHostResolver);

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    ['endpoint' => $this->endpoint] = WebhookEndpoint::issue('CRM', 'https://hook.example.test/w', []);
});

function pendingDelivery(array $overrides = []): WebhookDelivery
{
    return WebhookDelivery::create(array_merge([
        'webhook_endpoint_id' => test()->endpoint->getKey(),
        'event_type' => 'delivered',
        'message_uuid' => (string) Str::uuid(),
        'payload' => ['event' => 'delivered'],
        'status' => 'pending',
        'next_attempt_at' => now()->subMinute(),
    ], $overrides));
}

it('a PENDING sort is újrapróbálja', function () {
    // A job elhalhat, mielőtt a HTTP-hívásig jutna (worker-újraindulás, Redis-kiesés). Ha a
    // parancs csak a `failed`-et nézné, ez a sor ÖRÖKRE `pending` maradna — csendes vesztés.
    $delivery = pendingDelivery();

    Queue::fake();

    $this->artisan('webhooks:retry')->assertSuccessful();

    Queue::assertPushed(DeliverWebhook::class, 1);

    expect($delivery->fresh()->next_attempt_at->isFuture())->toBeTrue();
});

it('a LÍZING miatt két futás nem teszi kétszer sorba ugyanazt', function () {
    // Torlódásnál (200 esedékes sor, percenkénti futás) enélkül az ügyfél végpontja
    // percenként újra megkapná ugyanazt az eseményt.
    pendingDelivery();

    Queue::fake();

    $this->artisan('webhooks:retry')->assertSuccessful();
    $this->artisan('webhooks:retry')->assertSuccessful();

    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('a lízing LEJÁRTA után viszont újra sorra kerül', function () {
    pendingDelivery();

    Queue::fake();

    $this->artisan('webhooks:retry')->assertSuccessful();

    $this->travel(6)->minutes();

    $this->artisan('webhooks:retry')->assertSuccessful();

    Queue::assertPushed(DeliverWebhook::class, 2);
});

it('a megfejthetetlen titok NEM dead-letter, és nem is fogyaszt kísérletet', function () {
    // APP_KEY-rotáció (vagy elrontott env) esetén enélkül MINDEN ügyfél MINDEN webhookja
    // hatszor „hálózati hibát" mutatna, aztán dead-letter lenne — egy elgépelt URL-től
    // megkülönböztethetetlenül.
    $delivery = pendingDelivery();

    // A titkosított értéket MÁSIK kulccsal állítjuk elő: pontosan ez történik APP_KEY-
    // rotációnál. Futásidőben, mert egy beégetett titkosított bájtsor a titok-szkennernek
    // (helyesen) gyanús — és attól még nem lenne valósághűbb.
    $masikKulcs = new Encrypter(random_bytes(32), config('app.cipher'));

    DB::table('webhook_endpoints')
        ->where('id', $this->endpoint->getKey())
        ->update(['secret' => $masikKulcs->encryptString('whsec_'.Str::random(40))]);

    Http::fake();

    (new DeliverWebhook((int) $this->workspace->getKey(), (string) $delivery->uuid))
        ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));

    $delivery->refresh();

    expect($delivery->status)->toBe('failed');
    expect($delivery->attempt)->toBe(0);
    expect($delivery->response_excerpt)->toContain('konfigurációs');
    expect($delivery->dead_lettered_at)->toBeNull();

    // …és a kérés EL SEM MENT: aláírás nélkül nincs mit küldeni.
    Http::assertNothingSent();
});

it('a FELOLDHATATLAN hosztnév újrapróbálás, nem dead-letter', function () {
    // A DNS-kiesés átmeneti. Az ügyfél eseményét nem dobjuk el miatta.
    $this->app->instance(HostResolver::class, new FakeHostResolver(['hook.example.test' => []]));

    $delivery = pendingDelivery();

    Http::fake();

    (new DeliverWebhook((int) $this->workspace->getKey(), (string) $delivery->uuid))
        ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));

    $delivery->refresh();

    expect($delivery->status)->toBe('failed');
    expect($delivery->dead_lettered_at)->toBeNull();
    expect($delivery->next_attempt_at->isFuture())->toBeTrue();

    Http::assertNothingSent();
});

it('a TILTOTT cél viszont azonnal dead-letter', function () {
    // Ez nem múlik el magától: az újrapróbálás csak ismételné a belső hálózati kérést.
    $this->app->instance(HostResolver::class, new FakeHostResolver(['hook.example.test' => ['169.254.169.254']]));

    $delivery = pendingDelivery();

    Http::fake();

    (new DeliverWebhook((int) $this->workspace->getKey(), (string) $delivery->uuid))
        ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));

    expect($delivery->fresh()->status)->toBe('dead_letter');

    Http::assertNothingSent();
});

it('a végpontlista kérésenként EGYSZER kérdeződik le', function () {
    // Egy 1000 címzettes batch címzettenként külön eseményt ír. Eseményenként új
    // lekérdezéssel ez 1000 SELECT lenne a `POST /v1/messages` kérésen BELÜL — kétszer, az
    // `accepted` és a `delivered` miatt.
    Queue::fake();

    $dispatcher = app(WebhookDispatcher::class);

    $lekerdezesek = 0;

    DB::listen(function ($query) use (&$lekerdezesek): void {
        if (str_contains($query->sql, 'webhook_endpoints')) {
            $lekerdezesek++;
        }
    });

    foreach (range(1, 20) as $ignored) {
        $dispatcher->dispatch(MessageEvent::create([
            'message_uuid' => (string) Str::uuid(),
            'type' => 'delivered',
            'recipient' => 'cimzett@example.test',
            'channel' => 'transactional',
            'tags' => [],
        ]));
    }

    expect($lekerdezesek)->toBe(1);
});
