<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A Postal → ventMail visszacsatolás (deploy-ovh 2. pont). EXPAND-ONLY: két új tábla,
 | meglévőhöz nem nyúl — a rollback adatbázis-visszaállítás nélkül működik.
 |
 | `postal_message_refs`: a Postal címzettenkénti tokenje → ventMail-üzenet + címzett + pool.
 | A webhook csak ezt a tokent hozza; enélkül a bounce nem lenne kihez kötni.
 |
 | `postal_webhook_receipts`: a feldolgozott webhookok uuid-ja. A Postal újrapróbál, ha nem
 | kap 2xx-et — ugyanaz az esemény kétszer nem írhat kétszer kísérletet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postal_message_refs', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64);
            $table->string('pool', 16);
            $table->uuid('message_uuid')->index();
            $table->string('recipient');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // A token CSAK a saját példányán belül egyedi: két Postal-példány kioszthatja
            // ugyanazt — a pool a kulcs része.
            $table->unique(['pool', 'token']);
            $table->index('created_at');
        });

        Schema::create('postal_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('pool', 16);
            $table->string('uuid', 64);
            $table->string('event', 48);
            $table->timestampTz('received_at')->index();

            $table->unique(['pool', 'uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postal_webhook_receipts');
        Schema::dropIfExists('postal_message_refs');
    }
};
