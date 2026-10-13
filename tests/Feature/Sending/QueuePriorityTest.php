<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Data\SendQueue;
use App\Modules\Sending\Jobs\DeliverMessage;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\DomainThrottle;
use App\Modules\Sending\Services\RetryPolicy;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 | 18–19. kritérium — sor-prioritás és visszalépés.
 |
 | Golden rule 4: a `campaign` sor SOHA nem előzheti meg a `transactional`-t. Egy százezres
 | kampány mellett a jelszó-visszaállító levél különben órákat várna a sorban — ez a
 | leggyakoribb ESP-panasz, és nálunk szabály.
 */

it('a csatorna determinálja a sort', function () {
    expect(SendQueue::forChannel('transactional'))->toBe(SendQueue::Transactional);
    expect(SendQueue::forChannel('journey'))->toBe(SendQueue::Journey);
    expect(SendQueue::forChannel('campaign'))->toBe(SendQueue::Campaign);
    // Ismeretlen csatorna a LEGALACSONYABB prioritást kapja, nem a legmagasabbat.
    expect(SendQueue::forChannel('marketing'))->toBe(SendQueue::Campaign);
});

it('a prioritási sorrend rögzített', function () {
    expect(SendQueue::priorityOrder())->toBe(['transactional', 'journey', 'campaign']);
});

it('a telített campaign sor mellett a transactional megy ki ELŐBB', function () {
    // Valódi queue-connection, valódi `pop` — nem a jobok elnevezését hisszük el. A worker
    // a `priorityOrder()` szerint keres munkát: a tranzakciós sor akkor is előbb ürül, ha a
    // kampány-sorban ötven job vár.
    config(['queue.default' => 'database']);

    foreach (range(1, 50) as $i) {
        DeliverMessage::dispatch(1, 'kampany-'.$i)->onQueue(SendQueue::Campaign->value);
    }

    DeliverMessage::dispatch(1, 'surgos-uuid')->onQueue(SendQueue::Transactional->value);

    $first = null;

    foreach (SendQueue::priorityOrder() as $queue) {
        $job = Queue::connection('database')->pop($queue);

        if ($job !== null) {
            $first = $job->getRawBody();

            break;
        }
    }

    expect($first)->toContain('surgos-uuid');
});

it('a worker-konfiguráció a prioritási sorrendet hordozza', function () {
    // Ha valaki a Horizon-configban átrendezi a sorokat, a kód-szintű prioritás semmit nem ér.
    // A küldő supervisor minden környezetben, ahol fut, ugyanezt a sorrendet hordozza.
    foreach (['production', 'local', 'testing'] as $env) {
        expect(config("horizon.environments.{$env}.sending.queue"))->toBe(SendQueue::priorityOrder());
    }
});

it('a visszalépés exponenciális és van felső korlátja', function () {
    $policy = app(RetryPolicy::class);

    $delays = array_map(fn (int $attempt) => $policy->delaySeconds($attempt), [1, 2, 3, 4, 5]);

    expect($delays)->toBe([60, 120, 240, 480, 960]);
    // Egy 4xx nem tarthat örökké: felső korlát nélkül a sor sosem ürülne ki.
    expect($policy->delaySeconds(20))->toBeLessThanOrEqual($policy->maxDelaySeconds());
    expect($policy->shouldGiveUp(attempt: $policy->maxAttempts() + 1))->toBeTrue();
});

it('a fogadó-domain throttle KÜLÖN bucketben fut', function () {
    // A vödör kulcsa perchez kötött: percváltás közben a kimerítés „elfelejtődik".
    $this->freezeTime();

    $throttle = app(DomainThrottle::class);

    // A gmail.com kimerítése nem érintheti az example.test-et.
    foreach (range(1, $throttle->limitFor('gmail.com')) as $i) {
        expect($throttle->allow('gmail.com'))->toBeTrue();
    }

    expect($throttle->allow('gmail.com'))->toBeFalse();
    expect($throttle->allow('example.test'))->toBeTrue();
});

it('a throttle ablaka lejár', function () {
    // A fagyasztás nélkül a kimerítés és az ellenőrzés KÖZÉ eshetne a percváltás, és a
    // teszt akkor is zöld lenne, ha a lejárat nem működne.
    $this->freezeTime();

    $throttle = app(DomainThrottle::class);

    foreach (range(1, $throttle->limitFor('gmail.com')) as $i) {
        $throttle->allow('gmail.com');
    }

    expect($throttle->allow('gmail.com'))->toBeFalse();

    $this->travelTo(now()->addMinute()->addSecond());

    expect($throttle->allow('gmail.com'))->toBeTrue();
});

it('bekapcsolt sorral a küldés a CSATORNA sorára kerül, nem szinkron megy', function () {
    // A sor a prioritás egyetlen érvényesítési helye: szinkron úton nincs prioritás.
    config(['sending.dispatch_via_queue' => true]);

    Queue::fake();

    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['cimzett@example.test'],
        'subject' => 'Teszt',
        'html' => '<p>Szia</p>',
        'idempotency_key' => 'q-1',
    ]);

    Queue::assertPushedOn(SendQueue::Transactional->value, DeliverMessage::class);
});

it('a fogadó-domain throttle VALÓBAN fut a küldési úton', function () {
    // „Meg van írva, de senki nem hívja" — ez a teszt pont ezt zárja ki.
    //
    // AZ IDŐ FAGYASZTVA: a throttle kulcsa PERCHEZ kötött (`YmdHi`), tehát ha a percváltás
    // a vödör feltöltése és a küldés KÖZÉ esik, a levél friss vödörbe kerül és kimegy —
    // a teszt óránként egyszer, kiszámíthatatlanul elbukott (a párhuzamos futásban mérve).
    $this->freezeTime();

    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $throttle = app(DomainThrottle::class);

    foreach (range(1, $throttle->limitFor('example.test')) as $i) {
        $throttle->allow('example.test');
    }

    $result = app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['cimzett@example.test'],
        'subject' => 'Teszt',
        'html' => '<p>Szia</p>',
        'idempotency_key' => 't-1',
    ]);

    expect($result->message->fresh()->status)->toBe('deferred');
    expect($result->message->fresh()->reason)->toBe('DOMAIN_THROTTLED');
    expect($result->message->fresh()->next_attempt_at)->not->toBeNull();
});

it('a halasztott levelet a seprő a next_attempt_at szerint veszi elő', function () {
    // A korábbi seprő fix ötperces lízik alapján dolgozott: az exponenciális visszalépés
    // élesben NEM létezett, egy throttle-olt levél ötpercenként ment neki ugyanannak a
    // fogadónak — pont az, amit a backoff megelőzni hivatott.
    $workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($workspace);
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $message = Message::create([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'recipients' => ['cimzett@example.test'],
        'subject' => 'Teszt',
        'options' => ['body_html' => '<p>Szia</p>'],
        'status' => 'deferred',
        'attempts' => 1,
        'gate_decided_at' => now(),
        'next_attempt_at' => now()->addMinutes(30),
    ]);

    test()->artisan('messages:dispatch-scheduled')->assertSuccessful();
    app(WorkspaceContext::class)->set($workspace);

    // Még nem esedékes.
    expect($message->fresh()->status)->toBe('deferred');

    test()->travelTo(now()->addMinutes(31));

    test()->artisan('messages:dispatch-scheduled')->assertSuccessful();
    app(WorkspaceContext::class)->set($workspace);

    expect($message->fresh()->status)->toBe('delivered');
});
