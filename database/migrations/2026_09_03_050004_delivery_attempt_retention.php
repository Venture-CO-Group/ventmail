<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A kézbesítési kísérletek RETENCIÓJA (golden rule 10).
 |
 | A tábla teljes címzett-címet és a fogadó válasz-szövegét tárolja — az utóbbi gyakran az
 | eredeti tárgyat is idézi. PII-tároló retenció nélkül nem maradhat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->timestampTz('expires_at')->nullable()->after('classification')->index();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_attempts', fn (Blueprint $table) => $table->dropColumn('expires_at'));
    }
};
