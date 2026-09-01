<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | IP-poolok és warmup (spec 5.). A marketing/transactional szeparáció a poolon dől el:
 | marketing IP nem használható tranzakciós forgalomra (A2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_pools', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('shared');       // shared|dedicated
            $table->string('channel')->nullable();           // transactional|marketing|null=bármelyik
            $table->timestamps();
        });

        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_pool_id')->constrained()->cascadeOnDelete();
            $table->string('address')->unique();
            $table->string('hostname')->nullable();

            // Warmup-automatika: napi volumen-görbe új IP-n.
            $table->string('warmup_state')->default('cold');  // cold|warming|warm|quarantined
            $table->timestamp('warmup_started_at')->nullable();
            $table->unsignedInteger('daily_limit')->default(50);
            $table->unsignedInteger('sent_today')->default(0);

            // Reputáció a bounce/complaint/deferral aggregátumból.
            $table->decimal('reputation_score', 5, 2)->default(100);
            $table->string('status')->default('active');      // active|paused|quarantined
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
        Schema::dropIfExists('ip_pools');
    }
};
