<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | M0-2 elfogadási kritérium #12 — a message_events VALÓDI Timescale hypertable.
 | A napló és a marketing analitika (A3, B4) ugyanerre az esemény-forrásra épül.
 */

it('a message_events hypertable', function () {
    $hypertables = DB::select(
        "select hypertable_name from timescaledb_information.hypertables where hypertable_name = 'message_events'"
    );

    expect($hypertables)->not->toBeEmpty('a message_events nem hypertable');
});

it('a hypertable fogad insertet és visszaolvasható', function () {
    $workspace = Workspace::factory()->create();

    DB::table('message_events')->insert([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'message_uuid' => (string) Str::uuid(),
        'type' => 'delivered',
        'recipient' => 'cimzett@example.test',
        'channel' => 'transactional',
        'tags' => '{}',
        'variables' => '{}',
        'payload' => '{}',
        'occurred_at' => now(),
    ]);

    expect(DB::table('message_events')->count())->toBe(1);
});

it('a particionáló oszlop része a kulcsnak', function () {
    // Timescale-kényszer: minden UNIQUE/PK-nak tartalmaznia kell a particionáló oszlopot.
    $indexes = DB::select("
        select indexdef from pg_indexes
        where tablename = 'message_events' and indexdef like '%UNIQUE%'
    ");

    // Enélkül a teszt üres halmaznál is zöld lenne, azaz akkor is, ha egyetlen unique
    // index sincs a táblán.
    expect($indexes)->not->toBeEmpty('nincs unique index a message_events táblán');

    foreach ($indexes as $index) {
        expect($index->indexdef)->toContain('occurred_at');
    }
});
