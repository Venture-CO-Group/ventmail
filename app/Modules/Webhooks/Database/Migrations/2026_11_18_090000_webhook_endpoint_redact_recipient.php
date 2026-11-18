<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Kézbesítési-esemény webhookok — EXPAND-ONLY.
 |
 | `redact_recipient`: a végpont NEM kapja meg a nyers címzett-címet (`message.recipient = null`),
 | csak a maszkolt alakot (`message.recipient_masked`). Alapértéke `false`, tehát a meglévő
 | végpontok szerződése változatlan; az integráció (pl. Jersey Magic) maga kapcsolja be.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('webhook_endpoints', 'redact_recipient')) {
            Schema::table('webhook_endpoints', function (Blueprint $table): void {
                $table->boolean('redact_recipient')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->dropColumn('redact_recipient');
        });
    }
};
