<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Webhookok (28. kritérium).
 |
 | Miért kell a KÉZBESÍTÉSI napló külön tábla: a webhook a mi ígéretünk a hívó felé. Ha egy
 | esemény nem jut el, azt látni kell — mikor próbáltuk, mit válaszolt, és MIT küldtünk.
 | A dead-letter sorból az UI-ból újra lehet játszani (replay), és a sikeres replay kivezeti
 | a sort — enélkül a DLQ csak egy növekvő szemétdomb lenne.
 |
 | A titok HASH-elve tárolódik: a nyers érték egyszer, létrehozáskor látszik. A HMAC-hez
 | viszont a NYERS titok kell, ezért titkosítva (nem hash-elve) is tároljuk — a
 | `Crypt::encryptString` a `APP_KEY`-hez köti, tehát adatbázis-szivárgás önmagában nem elég
 | az aláírás hamisításához.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->text('secret');                       // titkosítva (HMAC-hez kell a nyers)
            $table->jsonb('events')->default('[]');       // mely eseménytípusokra
            $table->boolean('active')->default(true);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();

            $table->string('event_type');
            $table->uuid('message_uuid')->nullable()->index();
            $table->jsonb('payload');

            $table->unsignedSmallInteger('attempt')->default(0);
            $table->string('status')->default('pending'); // pending|delivered|failed|dead_letter
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_excerpt')->nullable();  // a válasz ELEJE, nem az egész

            $table->timestampTz('next_attempt_at')->nullable()->index();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('dead_lettered_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
