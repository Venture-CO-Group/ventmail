<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\IpPools\Services\IpPoolSelector;
use App\Modules\IpPools\Services\ReputationMonitor;
use App\Modules\IpPools\Services\WarmupSchedule;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | 16–17. kritérium — IP-pool hozzárendelés és warmup.
 |
 | Miért blocking: a reputáció IP-hez tapad, nem tenanthoz. Ha a marketing-forgalom
 | ugyanarról az IP-ről megy, mint a jelszó-visszaállító levél, akkor egyetlen kampány
 | spam-jelölése után a TRANZAKCIÓS levél sem érkezik meg. A warmup napi limitje
 | ugyanígy: a friss IP-ről kiengedett 100 000 levél azonnali blocklistát jelent.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);
    $this->domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
});

function ipPool(array $attributes = [], array $ip = []): IpPool
{
    $pool = IpPool::create(array_merge(['name' => 'p-'.bin2hex(random_bytes(3)), 'type' => 'shared'], $attributes));

    IpAddress::create(array_merge([
        'ip_pool_id' => $pool->getKey(),
        'address' => '203.0.113.'.random_int(1, 250),
        'warmup_state' => 'warm',
        'daily_limit' => 1000,
        'status' => 'active',
    ], $ip));

    return $pool->fresh();
}

function outgoingMessage(string $channel = 'transactional', array $attributes = []): Message
{
    return Message::create(array_merge([
        'channel' => $channel,
        'from_email' => 'no-reply@pelda.hu',
        'recipients' => ['cimzett@example.test'],
        'status' => 'accepted',
        'gate_decided_at' => now(),
        'options' => [],
    ], $attributes));
}

it('a marketing pool NEM vihet tranzakciós forgalmat', function () {
    $marketing = ipPool(['channel' => 'marketing']);

    expect(app(IpPoolSelector::class)->select(outgoingMessage('transactional'), $marketing))->toBeNull();
    expect(app(IpPoolSelector::class)->select(outgoingMessage('marketing'), $marketing))->not->toBeNull();
});

it('a domainhez rendelt pool erősebb az általánosnál', function () {
    $general = ipPool();
    $assigned = ipPool();
    $assigned->domains()->attach($this->domain);

    $selected = app(IpPoolSelector::class)->select(outgoingMessage('transactional', ['domain_id' => $this->domain->getKey()]));

    expect($selected?->ip_pool_id)->toBe($assigned->getKey());
    expect($selected?->ip_pool_id)->not->toBe($general->getKey());
});

it('a warmup napi limitje betartva — a limit fölött nincs IP', function () {
    $pool = ipPool([], ['daily_limit' => 2, 'sent_today' => 0, 'warmup_state' => 'warming']);

    $selector = app(IpPoolSelector::class);

    expect($selector->claim(outgoingMessage(), $pool))->not->toBeNull();
    expect($selector->claim(outgoingMessage(), $pool))->not->toBeNull();
    // A harmadik már nem kap IP-t: a küldés SORBAN marad, nem megy ki limit fölött.
    expect($selector->claim(outgoingMessage(), $pool))->toBeNull();
});

it('a napi számláló DÁTUMHOZ kötött — nem kell éjféli reset-job', function () {
    // Ha a resetet egy cron végezné, annak elmaradása csendben megállítaná a küldést.
    $pool = ipPool([], ['daily_limit' => 1, 'sent_today' => 1, 'sent_on' => now()->toDateString()]);

    expect(app(IpPoolSelector::class)->claim(outgoingMessage(), $pool))->toBeNull();

    $this->travelTo(now()->addDay());

    expect(app(IpPoolSelector::class)->claim(outgoingMessage(), $pool))->not->toBeNull();
});

it('a warmup-görbe napról napra emel', function () {
    $schedule = app(WarmupSchedule::class);

    expect($schedule->limitForDay(1))->toBeLessThan($schedule->limitForDay(2));
    expect($schedule->limitForDay(2))->toBeLessThan($schedule->limitForDay(7));
    // A görbe VÉGES: a warmup befejeztével nincs mesterséges plafon.
    expect($schedule->isComplete($schedule->days() + 1))->toBeTrue();
});

it('a reputáció romlásakor a napi limit VISSZAFOGÓDIK', function () {
    $pool = ipPool([], ['daily_limit' => 10000, 'reputation_score' => 100]);
    $ip = $pool->addresses()->firstOrFail();

    app(ReputationMonitor::class)->apply($ip, bounceRate: 0.12, complaintRate: 0.001);

    $ip->refresh();

    expect($ip->reputation_score)->toBeLessThan(100.0);
    expect($ip->daily_limit)->toBeLessThan(10000);
    expect($ip->status)->toBe('active'); // visszafogás, még nem karantén
});

it('a beteg IP KARANTÉNBA kerül és nem kap forgalmat', function () {
    $pool = ipPool([], ['daily_limit' => 10000]);
    $ip = $pool->addresses()->firstOrFail();

    // Panaszarány, ami mellett minden további levél a domain reputációját rombolja.
    app(ReputationMonitor::class)->apply($ip, bounceRate: 0.3, complaintRate: 0.02);

    $ip->refresh();

    expect($ip->status)->toBe('quarantined');
    expect($ip->isAvailable())->toBeFalse();
    expect(app(IpPoolSelector::class)->claim(outgoingMessage(), $pool->fresh()))->toBeNull();
});

it('a karanténba tett IP nem választható akkor sem, ha ő az egyetlen a poolban', function () {
    // A „legalább küldjünk valamivel" viselkedés a legrosszabb: pont a beteg IP-t hajtaná.
    $pool = ipPool([], ['status' => 'quarantined']);

    expect(app(IpPoolSelector::class)->select(outgoingMessage(), $pool))->toBeNull();
});

it('a pool-választás tenant-izolált: idegen dedikált pool nem választható', function () {
    // A MÁSIK tenant kontextusában létrehozott dedikált pool — a global scope miatt nem is
    // látszik nálunk, tehát nem is választható (golden rule 6).
    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);
    ipPool(['type' => 'dedicated']);

    app(WorkspaceContext::class)->set($this->workspace);

    expect(app(IpPoolSelector::class)->select(outgoingMessage('transactional')))->toBeNull();
});

/*
 | A warmup és a reputáció AUTOMATIKUS — nem kézi oszlop-szerkesztés.
 */

it('a warmup-görbe lépteti a napi limitet', function () {
    // Enélkül a „napi limit betartva" azt jelentené, hogy tiszteletben tartjuk azt a
    // számot, amit valaki kézzel beírt az oszlopba.
    $pool = ipPool([], ['warmup_started_at' => now()->subDays(2), 'daily_limit' => 50, 'warmup_state' => 'warming']);
    $address = $pool->addresses()->firstOrFail();

    test()->artisan('ip:reputation')->assertSuccessful();

    // A harmadik nap kerete a görbéből jön.
    expect($address->fresh()->daily_limit)->toBe(app(WarmupSchedule::class)->limitForDay(3));
});

it('a warmup végén az IP warm lesz', function () {
    $pool = ipPool([], ['warmup_started_at' => now()->subDays(30), 'warmup_state' => 'warming']);

    test()->artisan('ip:reputation')->assertSuccessful();

    expect($pool->addresses()->firstOrFail()->fresh()->warmup_state)->toBe('warm');
});

it('a bounce-arányból automatikus visszafogás lesz', function () {
    $pool = ipPool([], ['daily_limit' => 10000, 'warmup_state' => 'warm']);
    $address = $pool->addresses()->firstOrFail();

    // Tíz kiment levél, hat bounce — ez az arány már blocklist-közeli.
    foreach (range(1, 10) as $i) {
        $message = Message::create([
            'channel' => 'transactional',
            'from_email' => 'no-reply@pelda.hu',
            'recipients' => ['c'.$i.'@example.test'],
            'status' => 'sent',
            'ip_address_id' => $address->getKey(),
        ]);

        MessageEvent::create([
            'message_uuid' => $message->uuid,
            'type' => $i <= 6 ? 'bounced' : 'delivered',
            'recipient' => 'c'.$i.'@example.test',
            'channel' => 'transactional',
        ]);
    }

    test()->artisan('ip:reputation')->assertSuccessful();

    $fresh = $address->fresh();

    expect($fresh->status)->toBe('quarantined');
    expect($fresh->isAvailable())->toBeFalse();
});
