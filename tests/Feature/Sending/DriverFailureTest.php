<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\IpPools\Services\IpPoolSelector;
use App\Modules\Sending\Actions\RecordDeliveryOutcome;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Data\DispatchResult;
use App\Modules\Sending\Data\DriverCapabilities;
use App\Modules\Sending\Data\DriverHealth;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\DkimSigner;
use App\Modules\Sending\Services\DomainThrottle;
use App\Modules\Sending\Services\MessageDispatcher;
use App\Modules\Sending\Services\OutboundMimeBuilder;
use App\Modules\Sending\Services\RawMimeDkimSigner;
use App\Modules\Sending\Services\RetryPolicy;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | A legrosszabb hibamód egy e-mail-infrastruktúrában: az üzenet CSENDBEN elveszik.
 |
 | A dispatcher a claim után hívta a drivert try/catch nélkül. Kivétel esetén az állapot
 | `sending` maradt, esemény nem született, és mivel a `sending` no-op ágon volt, minden
 | újrapróbálkozás némán visszatért — a levél soha nem ment ki, és senki nem tudott róla.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
});

function acceptedMessage(): Message
{
    $result = app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'testmode' => true,
    ]);

    $message = $result->message;
    $message->forceFill(['testmode' => false])->save();

    return $message->fresh();
}

function failingDriver(): SendingDriver
{
    return new class implements SendingDriver
    {
        public function send(Message $message): DispatchResult
        {
            throw new RuntimeException('MTA timeout');
        }

        public function capabilities(): DriverCapabilities
        {
            return new DriverCapabilities(name: 'failing');
        }

        public function health(): DriverHealth
        {
            return new DriverHealth(healthy: false);
        }
    };
}

it('a driver kivétele nem tünteti el az üzenetet', function () {
    $message = acceptedMessage();

    $dispatcher = new MessageDispatcher(failingDriver(), app(WorkspaceContext::class), app(IpPoolSelector::class), app(OutboundMimeBuilder::class), app(DkimSigner::class), app(RawMimeDkimSigner::class), app(DomainThrottle::class), app(RecordDeliveryOutcome::class), app(RetryPolicy::class));

    expect(fn () => $dispatcher->dispatch($message))->toThrow(RuntimeException::class);

    $friss = $message->fresh();

    expect($friss->status)->toBe('deferred');
    expect(MessageEvent::where('type', 'deferred')->count())->toBe(1);
});

it('a hibára futott üzenet ÚJRAPRÓBÁLHATÓ', function () {
    $message = acceptedMessage();

    try {
        (new MessageDispatcher(failingDriver(), app(WorkspaceContext::class), app(IpPoolSelector::class), app(OutboundMimeBuilder::class), app(DkimSigner::class), app(RawMimeDkimSigner::class), app(DomainThrottle::class), app(RecordDeliveryOutcome::class), app(RetryPolicy::class)))->dispatch($message);
    } catch (RuntimeException) {
        // várt
    }

    // Az MTA visszatér: a következő próbálkozásnak sikerülnie kell.
    app(MessageDispatcher::class)->dispatch($message->fresh());

    expect($message->fresh()->status)->toBe('delivered');
});

it('a ragadt sending állapot lejárat után újraigényelhető', function () {
    $message = acceptedMessage();
    $message->forceFill(['status' => 'sending', 'sending_started_at' => now()->subHour()])->save();

    app(MessageDispatcher::class)->dispatch($message->fresh());

    expect($message->fresh()->status)->toBe('delivered');
});

it('a frissen sending állapotú üzenetet nem küldi el másodszor', function () {
    $message = acceptedMessage();
    $message->forceFill(['status' => 'sending', 'sending_started_at' => now()])->save();

    app(MessageDispatcher::class)->dispatch($message->fresh());

    expect($message->fresh()->status)->toBe('sending');
    expect(MessageEvent::where('type', 'delivered')->count())->toBe(0);
});

it('a driver hibaüzenete nem szivárog az üzenet reason mezőjébe', function () {
    $message = acceptedMessage();

    try {
        (new MessageDispatcher(failingDriver(), app(WorkspaceContext::class), app(IpPoolSelector::class), app(OutboundMimeBuilder::class), app(DkimSigner::class), app(RawMimeDkimSigner::class), app(DomainThrottle::class), app(RecordDeliveryOutcome::class), app(RetryPolicy::class)))->dispatch($message);
    } catch (RuntimeException) {
        // várt
    }

    expect((string) $message->fresh()->reason)->not->toContain('MTA timeout');
});
