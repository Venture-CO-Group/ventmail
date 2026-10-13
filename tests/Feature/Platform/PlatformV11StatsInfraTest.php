<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Deliverability\Models\BlocklistCheck;
use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Enums\Role;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Logs\Models\DomainDailyStat;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | v1.1 · 7. modul — küldési statisztika domainenként (D175) és a küldési infrastruktúra
 | platform-admin nézete (D176).
 */

beforeEach(function () {
    $this->withoutVite();
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    $this->domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->user = User::factory()->create();
    $this->workspace->members()->create(['user_id' => $this->user->id, 'roles' => [Role::Analyst->value]]);
});

function statSend(array $to = ['a@example.test']): Message
{
    app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Teszt',
        'to' => $to,
        'html' => '<p>x</p>',
        'idempotency_key' => 'st-'.bin2hex(random_bytes(5)),
    ]);

    return Message::query()->orderByDesc('id')->firstOrFail();
}

function statEvent(Message $message, string $type, array $payload = []): void
{
    MessageEvent::create([
        'message_uuid' => $message->uuid,
        'type' => $type,
        'recipient' => $message->recipients[0],
        'channel' => 'transactional',
        'payload' => $payload,
    ]);
}

it('a számlálók az esemény-folyamból nőnek, a driver második accepted-je nem duplázza a küldöttet', function () {
    $message = statSend(['a@example.test', 'b@example.test']);

    $before = DomainDailyStat::query()->where('domain_id', $this->domain->id)->value('sent');

    statEvent($message, 'accepted', ['provider_message_id' => 'p-1']);
    statEvent($message, 'delivered');
    statEvent($message, 'opened');
    statEvent($message, 'opened');
    statEvent($message, 'clicked');
    statEvent($message, 'bounced');
    statEvent($message, 'complained');

    $row = DomainDailyStat::query()->where('domain_id', $this->domain->id)->firstOrFail();

    expect($before)->toBe(2)
        ->and($row->sent)->toBe(2)
        // PONTOS érték: a fake driver két címzettre kézbesít, plusz a kézzel írt esemény.
        ->and($row->delivered)->toBe(3)
        ->and($row->opened)->toBe(2)
        ->and($row->clicked)->toBe(1)
        ->and($row->bounced)->toBe(1)
        ->and($row->complained)->toBe(1);
});

it('a statisztika-képernyő domainenként és napra bontva ad, idegen tenant nélkül', function () {
    $message = statSend();
    statEvent($message, 'delivered');
    statEvent($message, 'opened');

    $idegen = Workspace::factory()->create();
    app(WorkspaceContext::class)->runFor($idegen, function (): void {
        Domain::create(['name' => 'idegen.hu', 'status' => 'verified', 'verified_at' => now()]);
        app(SendMessage::class)([
            'channel' => 'transactional', 'from_email' => 'x@idegen.hu', 'to' => ['z@example.test'],
            'html' => '<p>x</p>', 'idempotency_key' => 'idegen-1',
        ]);
    });
    app(WorkspaceContext::class)->set($this->workspace);

    $this->actingAs($this->user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->get('/statistics?days=7')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Logs/DomainStats')
            ->where('days', 7)
            ->has('report.totals', 1)
            ->where('report.totals.0.domain', 'pelda.hu')
            ->where('report.totals.0.sent', 1)
            ->where('report.totals.0.opened', 1)
            ->where('report.sum.sent', 1)
            ->has('report.series', 1)
        )
        ->assertDontSee('idegen.hu');

    // Érvénytelen időszak → alapértelmezés; domain-szűrő.
    $this->actingAs($this->user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->get('/statistics?days=9999&domain='.$this->domain->uuid)
        ->assertInertia(fn ($page) => $page->where('days', 30)->where('domain', (string) $this->domain->uuid));
});

it('a statisztika a nyers események retenciója után is megmarad', function () {
    $message = statSend();
    statEvent($message, 'delivered');

    // A nyers esemény (Timescale drop_chunks helyett) — a számláló független tőle.
    expect(DomainDailyStat::query()->sum('delivered'))->toBeGreaterThanOrEqual(1);
    Message::query()->whereKey($message->getKey())->delete();
    expect(DomainDailyStat::query()->sum('delivered'))->toBeGreaterThanOrEqual(1);
});

it('az infrastruktúra-nézet csak platform-adminnak, csak olvasás, allowlist-tartalom nélkül', function () {
    config()->set('sending.allowlist', ['titkos-seed@pelda.hu']);

    $pool = IpPool::create(['name' => 'tx-pool', 'type' => 'shared', 'channel' => 'transactional']);
    $ip = IpAddress::create(['ip_pool_id' => $pool->getKey(), 'address' => '198.51.100.7', 'hostname' => 'mta1', 'daily_limit' => 1000, 'sent_today' => 10, 'warmup_state' => 'warming', 'status' => 'active']);
    BlocklistCheck::create(['ip_address_id' => $ip->getKey(), 'target' => '198.51.100.7', 'zone' => 'zen.spamhaus.org', 'listed' => true, 'checked_at' => now()]);

    $admin = User::factory()->create();
    $admin->forceFill(['platform_admin' => true])->save();
    // A platform-admin is valamely workspace tagjaként lép be (a route-stack tagságot kér).
    $this->workspace->members()->create(['user_id' => $admin->id, 'roles' => [Role::Analyst->value]]);

    $this->actingAs($admin)->get('/platform/infrastructure')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Platform/Infrastructure')
            ->where('addresses.0.address', '198.51.100.7')
            ->where('addresses.0.blocklist.0.listed', true)
            ->where('sendingMode.allowlistSize', 1)
            ->has('rateLimits.receivers')
        )
        ->assertDontSee('titkos-seed@pelda.hu');

    // Írási végpont nincs.
    $this->actingAs($admin)->post('/platform/infrastructure')->assertStatus(405);

    // Tenant-jogosultsággal (owner sem) nem elérhető.
    $owner = User::factory()->create();
    $this->workspace->members()->create(['user_id' => $owner->id, 'roles' => [Role::Owner->value]]);

    $this->actingAs($owner)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->get('/platform/infrastructure')->assertForbidden();
});

it('RS4: batch-úton egy írás +N, testmode nem „küldött", és a hibás recorder nem mérgezi a tranzakciót', function () {
    $message = statSend(['a@example.test', 'b@example.test', 'c@example.test']);

    expect(DomainDailyStat::query()->where('domain_id', $this->domain->id)->value('sent'))->toBe(3);

    app(SendMessage::class)([
        'channel' => 'transactional', 'from_email' => 'no-reply@pelda.hu', 'to' => ['t@example.test'],
        'html' => '<p>x</p>', 'idempotency_key' => 'testmode-1', 'testmode' => true,
    ]);

    expect(DomainDailyStat::query()->where('domain_id', $this->domain->id)->value('sent'))->toBe(3);

    // Egy elhasaló recorder a hívó tranzakcióját nem teszi használhatatlanná (savepoint).
    app()->instance(\App\Modules\Logs\Services\DomainStatsRecorder::class, new class {
        public function record(): void
        {
            \Illuminate\Support\Facades\DB::select('select 1/0');
        }
    });

    \Illuminate\Support\Facades\DB::transaction(function () use ($message): void {
        statEvent($message, 'opened');

        // Ha a tranzakció mérgezett lenne, ez „current transaction is aborted" hibát adna.
        expect(\Illuminate\Support\Facades\DB::selectOne('select 1 as ok')->ok)->toBe(1);
    });
});

it('N1 (D199): külső rollback után a következő tranzakció eseményei is számolódnak, a visszagördültek nem', function () {
    $message = statSend();
    $before = (int) DomainDailyStat::query()->where('domain_id', $this->domain->id)->value('opened');

    try {
        \Illuminate\Support\Facades\DB::transaction(function () use ($message): void {
            statEvent($message, 'opened');
            throw new \RuntimeException('rollback');
        });
    } catch (\RuntimeException) {
    }

    expect((int) DomainDailyStat::query()->where('domain_id', $this->domain->id)->value('opened'))->toBe($before);

    \Illuminate\Support\Facades\DB::transaction(function () use ($message): void {
        statEvent($message, 'opened');
        statEvent($message, 'opened');

        // Belső savepoint commitja nem viszi el a köteget.
        \Illuminate\Support\Facades\DB::transaction(fn () => statEvent($message, 'opened'));
    });

    expect((int) DomainDailyStat::query()->where('domain_id', $this->domain->id)->value('opened'))->toBe($before + 3);
});
