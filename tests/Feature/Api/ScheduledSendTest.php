<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | Az ütemezett küldés eddig ZSÁKUTCA volt: a hívó 202 „Queued. Thank you."-t kapott egy
 | soha ki nem küldött levélre, és a `decisionStale()` a későbbi kiküldést kivétellel
 | állította volna meg. 202 egy sosem kimenő levélre bizalmi kár.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
    ['plain' => $this->plain] = ApiKey::issue('CI', ['messages:send']);
});

/**
 * Az ütemező parancs a végén ELENGEDI a workspace-kontextust (helyesen: nem hagy maga után
 * tenant-kontextust egy háttérfolyamat). A tesztnek ezért vissza kell állítania, mielőtt
 * tenant-adatot kérdez.
 */
function runScheduler(): void
{
    test()->artisan('messages:dispatch-scheduled')->assertSuccessful();

    app(WorkspaceContext::class)->set(test()->workspace);
}

function scheduleSend(string $when, array $extra = []): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->plain,
        'Idempotency-Key' => 's-'.bin2hex(random_bytes(5)),
        'Accept' => 'application/json',
    ])->postJson('/v1/messages', array_merge([
        'from' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'subject' => 'Ütemezett',
        'html' => '<p>Szia</p>',
        'o:deliverytime' => $when,
    ], $extra));
}

it('az ütemezett üzenet az esedékességkor tényleg kimegy', function () {
    scheduleSend(now()->addHours(2)->toRfc2822String())->assertAccepted();

    expect(Message::firstOrFail()->status)->toBe('scheduled');

    $this->travelTo(now()->addHours(3));

    runScheduler();

    expect(Message::firstOrFail()->status)->toBe('delivered');
    expect(MessageEvent::where('type', 'delivered')->count())->toBe(1);
});

it('esedékesség előtt nem megy ki', function () {
    scheduleSend(now()->addHours(5)->toRfc2822String())->assertAccepted();

    runScheduler();

    expect(Message::firstOrFail()->status)->toBe('scheduled');
});

it('a kapuk ÚJRA lefutnak a kiküldés előtt', function () {
    // A gate-döntésnek szavatossági ideje van: a közben érkezett leiratkozásnak számítania
    // kell, különben egy holnapra ütemezett kampány a tegnapi állapot szerint menne ki.
    scheduleSend(now()->addHours(2)->toRfc2822String())->assertAccepted();

    Suppression::create(['email' => 'cimzett@example.test', 'reason' => 'complaint']);

    $this->travelTo(now()->addHours(3));
    runScheduler();

    $message = Message::firstOrFail();

    expect($message->status)->toBe('rejected');
    expect($message->reason)->toBe('SUPPRESSED');
    expect(MessageEvent::where('type', 'delivered')->count())->toBe(0);
});

it('a kétszer futtatott ütemező nem küld duplán', function () {
    scheduleSend(now()->addHour()->toRfc2822String())->assertAccepted();

    $this->travelTo(now()->addHours(2));

    runScheduler();
    runScheduler();

    expect(MessageEvent::where('type', 'delivered')->count())->toBe(1);
});

it('az ütemezett küldés tenantonként fut, kontextussal', function () {
    scheduleSend(now()->addHour()->toRfc2822String())->assertAccepted();

    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);

    $this->travelTo(now()->addHours(2));
    $this->artisan('messages:dispatch-scheduled')->assertSuccessful();

    // A parancs a saját kontextusát állítja be tenantonként — nem a hívóét használja.
    app(WorkspaceContext::class)->set($this->workspace);
    expect(Message::firstOrFail()->status)->toBe('delivered');
});
