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
 | A1 #4, #5 — a Mailgun `o:` vezérlők, `h:` fejlécek és `v:` változók.
 |
 | Ezek nélkül a meglévő integrációk nem hozhatók át kódmódosítás nélkül — az M1 globális
 | DoD-ja pontosan ezt kéri.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
    ['plain' => $this->plain] = ApiKey::issue('CI', ['messages:send']);
});

function control(array $overrides = []): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->plain,
        'Idempotency-Key' => 'k-'.bin2hex(random_bytes(5)),
        'Accept' => 'application/json',
    ])->postJson('/v1/messages', array_merge([
        'from' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'subject' => 'Teszt',
        'text' => 'Szia',
    ], $overrides));
}

it('o:deliverytime ütemez', function () {
    $when = now()->addHours(2);

    control(['o:deliverytime' => $when->toRfc2822String()])->assertAccepted();

    expect(Message::first()->deliverytime->timestamp)->toBe($when->timestamp);
    // Ütemezett üzenet NEM megy ki azonnal.
    expect(Message::first()->status)->toBe('scheduled');
});

it('o:deliverytime a múltban 400', function () {
    control(['o:deliverytime' => now()->subHour()->toRfc2822String()])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'DELIVERYTIME_IN_PAST');
});

it('o:deliverytime 7 napon túl 400', function () {
    control(['o:deliverytime' => now()->addDays(8)->toRfc2822String()])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'DELIVERYTIME_TOO_FAR');
});

it('o:testmode naplóz, de nem küld', function () {
    control(['o:testmode' => 'yes'])->assertAccepted();

    expect(Message::first()->testmode)->toBeTrue();
    expect(MessageEvent::where('type', 'delivered')->count())->toBe(0);
});

it('o:tag max három, a negyedik 400', function () {
    control(['o:tag' => ['egy', 'ketto', 'harom']])->assertAccepted();

    expect(Message::first()->tags)->toBe(['egy', 'ketto', 'harom']);

    control(['o:tag' => ['egy', 'ketto', 'harom', 'negy']])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'TOO_MANY_TAGS');
});

it('o:tracking vezérlők az üzenetre kerülnek', function () {
    control([
        'o:tracking' => 'yes',
        'o:tracking-clicks' => 'htmlonly',
        'o:tracking-opens' => 'no',
        'o:dkim' => 'yes',
        'o:require-tls' => 'true',
    ])->assertAccepted();

    $options = Message::first()->options;

    expect($options['tracking'])->toBeTrue();
    expect($options['tracking_clicks'])->toBe('htmlonly');
    expect($options['tracking_opens'])->toBeFalse();
    expect($options['require_tls'])->toBeTrue();
});

it('o:sending-ip-pool ismeretlen poolra 400', function () {
    control(['o:sending-ip-pool' => 'nincs-ilyen'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'UNKNOWN_IP_POOL');
});

it('h:X-* fejlécek átmennek a levélbe', function () {
    control(['h:X-Kampany' => 'tavasz', 'h:X-Egyedi-Id' => '42'])->assertAccepted();

    expect(Message::first()->headers)->toBe(['X-Kampany' => 'tavasz', 'X-Egyedi-Id' => '42']);
});

it('h: tiltott fejléccel a gate elutasít', function () {
    // A `Bcc` olyan címzettet adna, akit egyetlen kapu sem látott (M0-3 HeaderSafetyGate).
    control(['h:Bcc' => 'aldozat@example.test'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_REQUEST');
});

it('v:* változók visszajönnek az eseményben', function () {
    control(['v:user_id' => '42', 'v:plan' => 'pro'])->assertAccepted();

    $event = MessageEvent::where('type', 'delivered')->firstOrFail();

    expect($event->variables)->toMatchArray(['user_id' => '42', 'plan' => 'pro']);
});
