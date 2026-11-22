<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Kimenő csatolmány (H123) — a bájtok RÖVID életű tárolója.
 |
 | A csatolmány PII (pl. egy fan fotója), ezért nem a `messages` sorba kerül (az a napló
 | főtáblája), hanem ide, TITKOSÍTVA (`content`: az alkalmazás `encrypted` castja), és csak
 | addig, amíg a kiküldéshez / az újrapróbáláshoz kell. A lezárt üzenet sorai azonnal
 | törlődnek (modell-hook), a `messages:purge-attachments` pedig óránként takarítja a
 | lejártat és a hook elől elcsúszottat.
 |
 | Expand-only: új tábla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('message_uuid');
            $table->unsignedSmallInteger('position');
            $table->string('filename', 255);
            $table->string('content_type', 64);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->longText('content');
            $table->timestampTz('expires_at')->index();
            $table->timestamps();

            $table->unique(['message_uuid', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
