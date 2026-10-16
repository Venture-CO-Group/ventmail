<?php

declare(strict_types=1);

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Logs\Models\ErasedRecipient;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Services\UnsubscribeTokens;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Contracts\HostResolver;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Testing\FakeHostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    $this->message = Message::create([
        'channel' => 'marketing', 'from_email' => 'no-reply@pelda.hu', 'recipients' => ['anna@example.test'],
        'subject' => 'Hírlevél', 'headers' => [], 'tags' => ['vemoa', 'win_back'],
        'variables' => ['tenant_id' => '01J0000000000000000000000T', 'notification_id' => '01J0000000000000000000000N', 'recipient-variables' => ['a' => 1]],
    ]);
    $this->token = app(UnsubscribeTokens::class)->issue((int) $this->workspace->getKey(), (string) $this->message->uuid, 'anna@example.test');
    app(WorkspaceContext::class)->forget();
});

it('az egykattintásos leiratkozás `unsubscribed` eseményt ír, az üzenet v: változóival (webhookra megy)', function () {
    $this->post('/u/'.$this->token)->assertOk();

    $events = MessageEvent::query()->withoutGlobalScopes()->where('type', 'unsubscribed')->get();

    expect($events)->toHaveCount(1)
        ->and($events[0]->message_uuid)->toBe($this->message->uuid)
        ->and($events[0]->recipient)->toBe('anna@example.test')
        ->and($events[0]->channel)->toBe('marketing')
        ->and($events[0]->tags)->toBe(['vemoa', 'win_back'])
        ->and($events[0]->variables)->toBe(['tenant_id' => '01J0000000000000000000000T', 'notification_id' => '01J0000000000000000000000N']);
});

it('a Gmail-újrapróbálás (ismételt POST) nem ír második eseményt', function () {
    $this->post('/u/'.$this->token)->assertOk();
    $this->post('/u/'.$this->token)->assertOk();

    expect(MessageEvent::query()->withoutGlobalScopes()->where('type', 'unsubscribed')->count())->toBe(1);
});

it('a megerősítő oldal (GET) nem ír eseményt, az érvénytelen token sem', function () {
    $this->get('/u/'.$this->token)->assertOk();
    $this->post('/u/nem-ervenyes-token')->assertOk();

    expect(MessageEvent::query()->withoutGlobalScopes()->where('type', 'unsubscribed')->count())->toBe(0);
});

it('a webhook-előfizető megkapja: kézbesítési sor recipienttel és a v: változókkal', function () {
    Queue::fake();
    app()->instance(HostResolver::class, new FakeHostResolver);
    app(WorkspaceContext::class)->runFor($this->workspace, fn () => WebhookEndpoint::issue('Vemoa', 'https://vemoa.test/hook', ['unsubscribed']));

    $this->post('/u/'.$this->token)->assertOk();

    $delivery = WebhookDelivery::query()->withoutGlobalScopes()->where('event_type', 'unsubscribed')->sole();
    $payload = json_encode($delivery->payload, JSON_THROW_ON_ERROR);

    expect($delivery->message_uuid)->toBe($this->message->uuid)
        ->and($payload)->toContain('anna@example.test')
        ->and($payload)->toContain('01J0000000000000000000000N')
        ->and($payload)->not->toContain('recipient-variables');
});

it('az esemény a címzettet a küldés tárolt alakjában viszi (kis-/nagybetű), és így is egyszer', function () {
    app(WorkspaceContext::class)->set($this->workspace);
    $message = Message::create([
        'channel' => 'marketing', 'from_email' => 'no-reply@pelda.hu', 'recipients' => ['Anna.Kovacs@example.test'],
        'subject' => 'Hírlevél', 'headers' => [], 'variables' => ['notification_id' => 'N2'],
    ]);
    $upper = app(UnsubscribeTokens::class)->issue((int) $this->workspace->getKey(), (string) $message->uuid, 'Anna.Kovacs@example.test');
    $lower = app(UnsubscribeTokens::class)->issue((int) $this->workspace->getKey(), (string) $message->uuid, 'anna.kovacs@example.test');
    app(WorkspaceContext::class)->forget();

    $this->post('/u/'.$upper)->assertOk();
    $this->post('/u/'.$lower)->assertOk();

    $events = MessageEvent::query()->withoutGlobalScopes()->where('message_uuid', $message->uuid)->where('type', 'unsubscribed')->get();

    expect($events)->toHaveCount(1)
        ->and($events[0]->recipient)->toBe('Anna.Kovacs@example.test');
});

it('több címzettes üzenetnél címzettenként külön esemény', function () {
    app(WorkspaceContext::class)->set($this->workspace);
    $message = Message::create([
        'channel' => 'marketing', 'from_email' => 'no-reply@pelda.hu', 'recipients' => ['a@example.test', 'b@example.test'],
        'subject' => 'Hírlevél', 'headers' => [],
    ]);
    $tokens = app(UnsubscribeTokens::class);
    $ta = $tokens->issue((int) $this->workspace->getKey(), (string) $message->uuid, 'a@example.test');
    $tb = $tokens->issue((int) $this->workspace->getKey(), (string) $message->uuid, 'b@example.test');
    app(WorkspaceContext::class)->forget();

    $this->post('/u/'.$ta)->assertOk();
    $this->post('/u/'.$tb)->assertOk();

    expect(MessageEvent::query()->withoutGlobalScopes()->where('message_uuid', $message->uuid)->where('type', 'unsubscribed')
        ->pluck('recipient')->sort()->values()->all())->toBe(['a@example.test', 'b@example.test']);
});

it('GDPR-törlés után a régi levél leiratkozó linkje nem ír eseményt és nem küld webhookot', function () {
    Queue::fake();
    app()->instance(HostResolver::class, new FakeHostResolver);
    app(WorkspaceContext::class)->runFor($this->workspace, function () {
        WebhookEndpoint::issue('Vemoa', 'https://vemoa.test/hook', ['unsubscribed']);
        ErasedRecipient::create(['recipient_hash' => ErasedRecipient::hash('anna@example.test'), 'erased_at' => now()]);
        $this->message->forceFill(['recipients' => []])->save();
    });

    $this->post('/u/'.$this->token)->assertOk();

    expect(MessageEvent::query()->withoutGlobalScopes()->where('type', 'unsubscribed')->count())->toBe(0)
        ->and(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);
});
