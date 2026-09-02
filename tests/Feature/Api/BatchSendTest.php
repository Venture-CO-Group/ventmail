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
 | A1 #3 — batch küldés `recipient-variables`-szel.
 |
 | A batch lényege, hogy MINDEN címzett a saját behelyettesítését kapja. Ha mindenki
 | ugyanazt kapná, a batch értelmetlen lenne — és a Mailgunról átjövő integrációk
 | némán rossz leveleket küldenének.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
    ['plain' => $this->plain] = ApiKey::issue('CI', ['messages:send']);
});

function batchSend(array $payload): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->plain,
        'Idempotency-Key' => 'batch-'.bin2hex(random_bytes(5)),
        'Accept' => 'application/json',
    ])->postJson('/v1/messages', $payload);
}

it('minden címzett a SAJÁT behelyettesítését kapja', function () {
    batchSend([
        'from' => 'no-reply@pelda.hu',
        'to' => ['anna@example.test', 'bela@example.test'],
        'subject' => 'Egyenleg-értesítő',
        'html' => '<p>Szia %recipient.first_name%, egyenleged: %recipient.balance% Ft.</p>',
        'recipient-variables' => json_encode([
            'anna@example.test' => ['first_name' => 'Anna', 'balance' => 100],
            'bela@example.test' => ['first_name' => 'Béla', 'balance' => 250],
        ]),
    ])->assertAccepted();

    $rendered = MessageEvent::where('type', 'delivered')->get()
        ->mapWithKeys(fn ($event) => [$event->recipient => $event->payload['rendered_html'] ?? '']);

    expect($rendered['anna@example.test'])->toContain('Szia Anna')->toContain('100');
    expect($rendered['bela@example.test'])->toContain('Szia Béla')->toContain('250');

    // Nincs átszivárgás: Anna nem látja Béla adatát.
    expect($rendered['anna@example.test'])->not->toContain('Béla');
    expect($rendered['bela@example.test'])->not->toContain('Anna');
});

it('a behelyettesítés escape-elt', function () {
    batchSend([
        'from' => 'no-reply@pelda.hu',
        'to' => ['anna@example.test'],
        'html' => '<p>Szia %recipient.first_name%</p>',
        'recipient-variables' => json_encode([
            'anna@example.test' => ['first_name' => '<script>alert(1)</script>'],
        ]),
    ])->assertAccepted();

    $rendered = MessageEvent::where('type', 'delivered')->firstOrFail()->payload['rendered_html'] ?? '';

    expect($rendered)->not->toContain('<script>');
});

it('a %recipient_email% is behelyettesítődik', function () {
    batchSend([
        'from' => 'no-reply@pelda.hu',
        'to' => ['anna@example.test'],
        'html' => '<p>A címed: %recipient_email%</p>',
    ])->assertAccepted();

    expect(MessageEvent::where('type', 'delivered')->firstOrFail()->payload['rendered_html'])
        ->toContain('anna@example.test');
});

it('hiányzó címzett-változó fallback', function () {
    batchSend([
        'from' => 'no-reply@pelda.hu',
        'to' => ['anna@example.test', 'ismeretlen@example.test'],
        'html' => '<p>Szia %recipient.first_name%</p>',
        'recipient-variables' => json_encode(['anna@example.test' => ['first_name' => 'Anna']]),
    ])->assertAccepted();

    // A hiányzó változó nem akadályozza meg a küldést (Mailgun-paritás).
    expect(MessageEvent::where('type', 'delivered')->count())->toBe(2);
});

it('érvénytelen recipient-variables JSON esetén 400', function () {
    batchSend([
        'from' => 'no-reply@pelda.hu',
        'to' => ['anna@example.test'],
        'recipient-variables' => '{ nem json',
    ])->assertStatus(400)->assertJsonPath('error.code', 'INVALID_RECIPIENT_VARIABLES');
});

it('egyetlen üzenet-rekord keletkezik a batchre', function () {
    batchSend([
        'from' => 'no-reply@pelda.hu',
        'to' => ['a@example.test', 'b@example.test', 'c@example.test'],
        'html' => '<p>x</p>',
    ])->assertAccepted();

    // Egy hívás = egy üzenet, több címzett-esemény: így a napló és a kvóta is helyes.
    expect(Message::count())->toBe(1);
    expect(MessageEvent::where('type', 'delivered')->count())->toBe(3);
});
