<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A5 · Címellenőrzés — séma.
 |
 | A `validation_jobs` a BULK feldolgozás állapota, a `validation_results` a soronkénti
 | eredmény. Két tábla, mert a job életciklusa (feltöltés → futás → export) más, mint az
 | eredményeké (sok sor, rövid retenció).
 |
 | A CÍM személyes adat: a retenció rövid, és az érintetti törlés is eléri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name')->nullable();
            // pending | processing | completed | failed
            $table->string('status')->default('pending');
            $table->string('reason')->nullable();

            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('deliverable')->default(0);
            $table->unsignedInteger('risky')->default(0);
            $table->unsignedInteger('undeliverable')->default(0);
            $table->unsignedInteger('unknown')->default(0);

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('validation_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('validation_job_id')->constrained()->cascadeOnDelete();

            $table->string('address');
            $table->string('result');
            $table->decimal('risk', 4, 2)->default(0);
            $table->jsonb('reasons')->default('[]');
            $table->string('suggestion')->nullable();
            $table->timestampTz('redacted_at')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'validation_job_id']);
            $table->index(['validation_job_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_results');
        Schema::dropIfExists('validation_jobs');
    }
};
