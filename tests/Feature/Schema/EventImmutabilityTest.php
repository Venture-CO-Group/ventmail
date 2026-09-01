<?php

declare(strict_types=1);

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
