<?php

declare(strict_types=1);

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | Az esemény-napló integritása: a „miért nem érkezett meg?" (A3) és a marketing analitika
 | (B4) UGYANEBBŐL dolgozik. Egy utólag átírható napló mindkettőt hiteltelenné tenné.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);
});

it('az esemény nem módosítható utólag', function () {
    $event = MessageEvent::create([
        'message_uuid' => (string) Str::uuid(),
        'type' => 'delivered',
        'recipient' => 'cimzett@example.test',
        'channel' => 'transactional',
    ]);

    expect(fn () => $event->update(['type' => 'bounced']))->toThrow(RuntimeException::class);
});

it('az esemény a saját workspace-hez kötött', function () {
    $masik = Workspace::factory()->create();

    MessageEvent::create(['type' => 'accepted', 'recipient' => 'a@example.test']);

    app(WorkspaceContext::class)->set($masik);

    expect(MessageEvent::count())->toBe(0);
});

it('az occurred_at automatikusan kitöltődik', function () {
    $event = MessageEvent::create(['type' => 'accepted', 'recipient' => 'a@example.test']);

    expect($event->occurred_at)->not->toBeNull();
});

it('az érintetti törlés redakcióval teljesíthető', function () {
    // A drop_chunks chunk-granularitású: vele EGY embert nem lehet kivenni mindenki más
    // adatának eldobása nélkül. A redakció az egyetlen járható út (GDPR Art. 17).
    $event = MessageEvent::create([
        'type' => 'delivered',
        'recipient' => 'erintett@example.test',
        'variables' => ['user_id' => 42],
    ]);

    $event->redactPersonalData();

    $friss = $event->fresh();

    expect($friss->recipient)->toBeNull();
    expect($friss->variables)->toBe([]);
    expect($friss->redacted_at)->not->toBeNull();
    // Az esemény TÉNYE megmarad: a statisztika és a panasz-bizonyíték nem vész el.
    expect($friss->type)->toBe('delivered');
});

it('redakción kívül semmilyen átírás nem megy át', function () {
    $event = MessageEvent::create(['type' => 'delivered', 'recipient' => 'a@example.test']);

    expect(fn () => $event->update(['type' => 'bounced', 'redacted_at' => now()]))
        ->toThrow(RuntimeException::class);
});

it('be van állítva retenciós szabály a nyers eseményekre', function () {
    // Golden rule 10: lejárat után a nyers esemény törlődik.
    $policies = DB::select("select * from timescaledb_information.jobs
        where proc_name = 'policy_retention' and hypertable_name = 'message_events'");

    expect($policies)->not->toBeEmpty('nincs retenciós szabály a message_events táblán');
});
