<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Logs\Models\DomainDailyStat;
use App\Modules\Logs\Services\DomainStatistics;
use App\Modules\Sending\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AnalyticsFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 8. modul (D231) — a bounce TÍPUSA ugyanabban a kötegben íródik, mint a `bounced`
 | számláló. Ismeretlen besorolás: csak `bounced` (a grafikon „besorolatlan" sora).
 */

function vmBounce(Message $message, ?string $class): void
{
    DB::transaction(fn () => MessageEvent::create([
        'message_uuid' => $message->uuid,
        'type' => 'bounced',
        'recipient' => 'x@pelda.hu',
        'channel' => 'campaign',
        'payload' => $class === null ? [] : ['classification' => $class],
    ]));
}

it('a besorolás a saját oszlopába kerül, az ismeretlen csak a bounced-ot növeli', function () {
    AnalyticsFixtures::tenant();
    $message = AnalyticsFixtures::trackedMessage(AnalyticsFixtures::campaign());

    vmBounce($message, 'hard');
    vmBounce($message, 'hard');
    vmBounce($message, 'mailbox_full');
    vmBounce($message, 'ismeretlen');
    vmBounce($message, null);

    $row = DomainDailyStat::query()->sole();
    expect($row->bounced)->toBe(5)
        ->and((int) $row->bounced_hard)->toBe(2)
        ->and((int) $row->bounced_mailbox_full)->toBe(1)
        ->and((int) $row->bounced_soft)->toBe(0);

    $types = app(DomainStatistics::class)->bounceTypes(now()->subDay(), now());
    expect($types['total'])->toBe(5)
        ->and($types['classes']['hard'])->toBe(2)
        ->and($types['unclassified'])->toBe(2);
});

it('testmode üzenet bounce-a is besorolódik, de a küldött nem nő tőle', function () {
    AnalyticsFixtures::tenant();
    $message = AnalyticsFixtures::trackedMessage(AnalyticsFixtures::campaign());
    $message->forceFill(['testmode' => true])->save();

    DB::transaction(fn () => MessageEvent::create(['message_uuid' => $message->uuid, 'type' => 'accepted', 'recipient' => 'x@pelda.hu', 'channel' => 'campaign']));
    vmBounce($message, 'soft');

    $row = DomainDailyStat::query()->sole();
    expect($row->sent)->toBe(0)->and((int) $row->bounced_soft)->toBe(1);
});

it('rollback-kompatibilis: a régi kód (típus-oszlop nélküli upsert) a defaulttal működik', function () {
    [$workspace] = AnalyticsFixtures::tenant();
    $domain = Domain::query()->create(['name' => 'regi.hu', 'status' => 'verified', 'verified_at' => now()]);

    DomainDailyStat::query()->upsert(
        [['workspace_id' => $workspace->getKey(), 'domain_id' => $domain->getKey(), 'day' => now()->toDateString(), 'bounced' => 3, 'created_at' => now(), 'updated_at' => now()]],
        ['workspace_id', 'domain_id', 'day'],
        ['bounced'],
    );

    $types = app(DomainStatistics::class)->bounceTypes(now()->subDay(), now());
    expect($types['total'])->toBe(3)->and($types['unclassified'])->toBe(3)->and(array_sum($types['classes']))->toBe(0);
});
