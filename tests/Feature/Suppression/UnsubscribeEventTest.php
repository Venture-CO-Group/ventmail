<?php

declare(strict_types=1);

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Services\UnsubscribeTokens;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
