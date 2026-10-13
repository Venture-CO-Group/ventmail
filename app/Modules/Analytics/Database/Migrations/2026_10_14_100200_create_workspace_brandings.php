<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · 8. modul (D233, D236) — WORKSPACE-BRANDING a PDF-riporthoz. ÚJ TÁBLA (expand-only).
 |
 | Workspace-enként legfeljebb egy sor. Hiányzó sor / üres mező = a Venture alapértelmezése. A
 | sub-account NEM örököl a parenttől: a riport a saját workspace-e brandingjét viszi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workspace_brandings')) {
            return;
        }

        Schema::create('workspace_brandings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('primary_color', 7)->nullable();
            $table->string('company_name', 120)->nullable();
            $table->string('footer_text', 300)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('logo_mime', 32)->nullable();
            $table->unsignedInteger('logo_width')->nullable();
            $table->unsignedInteger('logo_height')->nullable();
            $table->unsignedInteger('logo_size')->nullable();
            $table->char('logo_sha256', 64)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_brandings');
    }
};
