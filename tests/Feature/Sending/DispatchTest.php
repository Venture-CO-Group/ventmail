<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Data\SendResult;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Drivers\LogDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\MessageDispatcher;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | M0-3 elfogadási kritérium #19, #20, #22 és az M0 GLOBÁLIS DoD (#28).
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
});

function sendResult(array $overrides = []): SendResult
{
    return app(SendMessage::class)(array_merge([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'subject' => 'Teszt',
        'html' => '<p>Szia</p>',
    ], $overrides));
}

it('M0 GLOBÁLIS DoD: hitelesített domainről a FakeDriveren delivered', function () {
    $result = sendResult();

    expect($result->decision->allowed)->toBeTrue();
    expect($result->message->status)->toBe('delivered');

    $types = MessageEvent::where('message_uuid', $result->message->uuid)
        ->orderBy('id')->pluck('type')->all();

    expect($types)->toBe(['accepted', 'delivered']);
});

it('a FakeDriver determinisztikus', function () {
    $first = sendResult(['idempotency_key' => 'a'])->message;
    $second = sendResult(['idempotency_key' => 'b'])->message;

    expect($first->status)->toBe($second->status);
    expect(MessageEvent::where('type', 'delivered')->count())->toBe(2);
});

it('kétszeri dispatch ugyanarra az üzenetre EGY küldés', function () {
    // testmode: a gate-en átmegy és `accepted` lesz, de a küldést nem indítja el —
    // így a dispatcher kétszeri hívása tisztán mérhető.
    $message = sendResult(['testmode' => true])->message;
    $message->forceFill(['testmode' => false])->save();

    $dispatcher = app(MessageDispatcher::class);
    $dispatcher->dispatch($message->fresh());
    $dispatcher->dispatch($message->fresh());

    // Az atomikus accepted → sending átmenetet az adatbázis egyszer engedi.
    expect(MessageEvent::where('message_uuid', $message->uuid)->where('type', 'delivered')->count())->toBe(1);
});

it('nem gate-elt üzenetet a dispatcher elutasít', function () {
    $message = Message::create([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'recipients' => ['cimzett@example.test'],
    ]);

    // `queued` állapot: nem ment át a gate-en.
    expect(fn () => app(MessageDispatcher::class)->dispatch($message))
        ->toThrow(RuntimeException::class);
});

it('ugyanaz az Idempotency-Key ugyanazt az üzenetet adja vissza', function () {
    $first = sendResult(['idempotency_key' => 'kulcs-1'])->message;
    $second = sendResult(['idempotency_key' => 'kulcs-1'])->message;

    expect($second->uuid)->toBe($first->uuid);
    expect(Message::count())->toBe(1);
});

it('a testmode naplóz, de nem küld', function () {
    $result = sendResult(['testmode' => true]);

    expect($result->message->status)->toBe('accepted');
    expect(MessageEvent::where('type', 'delivered')->count())->toBe(0);
    expect(MessageEvent::where('type', 'accepted')->count())->toBe(1);
});

it('az elutasított üzenet nem megy a driverhez', function () {
    Domain::query()->update(['status' => 'pending']);

    $result = sendResult();

    expect($result->decision->allowed)->toBeFalse();
    expect($result->message->status)->toBe('rejected');
    expect($result->message->reason)->not->toBeEmpty();
    expect(MessageEvent::where('type', 'delivered')->count())->toBe(0);
    expect(MessageEvent::where('type', 'rejected')->count())->toBe(1);
});

it('a driver-csere kizárólag config', function () {
    config(['sending.driver' => 'log']);
    app()->forgetInstance(SendingDriver::class);

    expect(app(SendingDriver::class))->toBeInstanceOf(LogDriver::class);

    config(['sending.driver' => 'fake']);
    app()->forgetInstance(SendingDriver::class);

    expect(app(SendingDriver::class))->toBeInstanceOf(FakeDriver::class);
});

it('a driver egészség- és képesség-jelentést ad', function () {
    $driver = app(SendingDriver::class);

    expect($driver->health()->healthy)->toBeTrue();
    expect($driver->capabilities()->name)->toBe('fake');
});

it('elavult gate-döntéssel nem indul küldés', function () {
    // Egy holnapra ütemezett kampánynál a közben érkezett leiratkozásnak számítania kell.
    $message = sendResult(['testmode' => true])->message;
    $message->forceFill(['testmode' => false, 'gate_decided_at' => now()->subDay()])->save();

    expect(fn () => app(MessageDispatcher::class)->dispatch($message->fresh()))
        ->toThrow(RuntimeException::class);
});

it('a küldés a feladó domainhez kötődik', function () {
    $message = sendResult()->message;

    expect($message->domain_id)->not->toBeNull();
    expect($message->domain->name)->toBe('pelda.hu');
});

it('batch küldésnél MINDEN címzett kap eseményt', function () {
    $recipients = ['egy@example.test', 'ketto@example.test', 'harom@example.test'];

    $message = sendResult(['to' => $recipients])->message;

    $delivered = MessageEvent::where('message_uuid', $message->uuid)
        ->where('type', 'delivered')->pluck('recipient')->all();

    expect($delivered)->toEqualCanonicalizing($recipients);
});

it('a FakeDriver determinisztikus azonosítót ad', function () {
    $message = sendResult()->message;

    $payload = MessageEvent::where('message_uuid', $message->uuid)
        ->where('type', 'delivered')->value('payload');

    expect($payload['provider_message_id'])->toBe('fake-'.$message->uuid);
});
