<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · 7. modul — DOMAINENKÉNTI NAPI STATISZTIKA (D175). ÚJ TÁBLA (expand-only).
 |
 | Miért nem a `message_event_daily` folytonos aggregátum: abban nincs küldő-domain (az
 | esemény nem hordozza), és Timescale folytonos aggregátum nem joinolhat a `messages`-re.
 | Miért nem futásidejű join: a nyers események a retencióval TÖRLŐDNEK (golden rule 10) —
 | az aggregátumnak viszont meg kell maradnia. Ezért egy kis számláló-tábla, amit az
 | esemény-folyam (`MessageEvent::created`) tölt, sor-szintű `+1` upserttel.
 |
 | `domain_id = 0` = ismeretlen / domain nélküli üzenet (a NULL az egyedi kulcsban nem
 | ütközne, és minden esemény új sort nyitna). Nincs FK: a statisztika a domain törlése
 | után is megmarad.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('domain_daily_stats')) {
            return;
        }

        Schema::create('domain_daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('domain_id')->default(0);
            $table->date('day');
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('delivered')->default(0);
            $table->unsignedInteger('bounced')->default(0);
            $table->unsignedInteger('complained')->default(0);
            $table->unsignedInteger('opened')->default(0);
            $table->unsignedInteger('clicked')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'domain_id', 'day']);
            $table->index(['workspace_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_daily_stats');
    }
};
