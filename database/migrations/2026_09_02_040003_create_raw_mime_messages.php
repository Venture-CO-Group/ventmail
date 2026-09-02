<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A beadott NYERS MIME megőrzése.
 |
 | Enélkül a `/v1/messages.mime` 202-t adott, a tartalom viszont a kérés végén megszűnt —
 | az éles driver ÜRES levelet küldött volna. A nyers MIME a levél maga: ha nem őrizzük
 | meg, nincs mit kiküldeni és nincs mit trace-elni.
 |
 | Retenció: a törzs PII-t hordoz, ezért a `messages`-hez hasonlóan retenció-alapú a
 | törlése (A3), és a nyers tárolás később Wasabira költözhet — a hivatkozás marad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_mime_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('message_uuid')->unique(); // egy üzenet = EGY nyers levél
            $table->binary('raw');
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_mime_messages');
    }
};
