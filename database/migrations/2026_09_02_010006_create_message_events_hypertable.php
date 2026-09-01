<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | message_events — TimescaleDB hypertable (spec 11., 13.).
 |
 | Ez az EGYETLEN esemény-forrás: a platform-napló (A3) és a marketing analitika (B4)
 | ugyanebből dolgozik — enélkül a két felület eltérő „delivered" számot mutatna.
 |
 | Timescale-kényszer: minden UNIQUE/PK kényszernek TARTALMAZNIA kell a particionáló
 | oszlopot, ezért a kulcs (id, occurred_at), és a uuid-unique is párban áll vele.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create extension if not exists timescaledb');

        Schema::create('message_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('message_uuid')->nullable();

            // accepted|rejected|delivered|deferred|bounced|complained|unsubscribed|opened|clicked|stored
            $table->string('type');
            $table->string('recipient')->nullable();
            $table->string('channel')->nullable();
            $table->string('reason')->nullable();

            $table->jsonb('tags')->default('[]');
            $table->jsonb('variables')->default('{}');
            $table->jsonb('payload')->default('{}');

            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->nullable();
        });

        // A Laravel által létrehozott PK-t le kell cserélni a particionáló oszlopot is
        // tartalmazó összetettre — enélkül a create_hypertable elutasítja a táblát.
        DB::statement('alter table message_events drop constraint message_events_pkey');
        DB::statement('alter table message_events add primary key (id, occurred_at)');
        DB::statement('alter table message_events add constraint message_events_uuid_unique unique (uuid, occurred_at)');

        DB::statement("select create_hypertable('message_events', 'occurred_at', chunk_time_interval => interval '7 days')");

        DB::statement('create index message_events_workspace_time_idx on message_events (workspace_id, occurred_at desc)');
        DB::statement('create index message_events_message_idx on message_events (message_uuid, occurred_at desc)');
        DB::statement('create index message_events_type_idx on message_events (workspace_id, type, occurred_at desc)');
    }

    public function down(): void
    {
        Schema::dropIfExists('message_events');
    }
};
