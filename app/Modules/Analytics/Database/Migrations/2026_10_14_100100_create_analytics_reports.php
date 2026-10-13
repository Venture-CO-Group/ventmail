<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · 8. modul — BRANDELT PDF-RIPORT kérései és állapota. ÚJ TÁBLA (expand-only).
 |
 | A RÉSZLEGES egyedi index a deduplikáció: ugyanaz a kérő ugyanazzal a paraméterrel
 | (`params_hash`) egyszerre csak EGY várakozó / renderelő riportot tarthat — a dupla kattintás
 | és a kliens-oldali újrapróbálás a meglévő sort kapja vissza, nem indít második Chromium-futást.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('analytics_reports')) {
            return;
        }

        Schema::create('analytics_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16);
            $table->uuid('campaign_uuid')->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('timezone', 64);
            $table->string('locale', 5);
            $table->jsonb('sections');
            $table->char('params_hash', 64);
            $table->string('status', 16)->default('queued');
            $table->string('failure_code', 32)->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement(
            'create unique index analytics_reports_inflight_unique on analytics_reports (workspace_id, requested_by, params_hash) '
            ."where status in ('queued', 'rendering')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_reports');
    }
};
