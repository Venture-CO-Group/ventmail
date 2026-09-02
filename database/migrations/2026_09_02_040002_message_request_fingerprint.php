<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Az idempotencia-kulcshoz tartozó KÉRÉS-LENYOMAT.
 |
 | A kulcs önmagában nem elég: ugyanaz a kulcs MÁS tartalommal eddig csendben eldobta a
 | második levelet (klasszikus eset: `order-123` a visszaigazolóra ÉS a szállítási
 | értesítőre). A lenyomat teszi lehetővé a `409 IDEMPOTENCY_KEY_REUSED` választ — ahogy a
 | Stripe és a Mailgun is teszi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->char('request_fingerprint', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('request_fingerprint');
        });
    }
};
