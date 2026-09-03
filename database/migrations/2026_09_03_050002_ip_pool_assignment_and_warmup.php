<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | IP-pool ↔ domain hozzárendelés és a warmup napi számlálója (16–17. kritérium).
 |
 | A `sent_today` mellé DÁTUM kerül. Ha a napi számlálót egy éjféli cron nullázná, annak
 | elmaradása CSENDBEN megállítaná a küldést (a limit örökre elfogyottnak látszana). A
 | dátumhoz kötött számláló magától fordul — nincs mit elfelejteni.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_pool_domain', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ip_pool_id', 'domain_id']);
        });

        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->date('sent_on')->nullable()->after('sent_today');
            $table->timestampTz('quarantined_at')->nullable()->after('status');
            $table->string('quarantine_reason')->nullable()->after('quarantined_at');
        });
    }

    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropColumn(['sent_on', 'quarantined_at', 'quarantine_reason']);
        });

        Schema::dropIfExists('ip_pool_domain');
    }
};
