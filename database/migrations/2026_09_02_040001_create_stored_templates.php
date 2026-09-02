<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Tárolt sablonok (spec 15.) — a Mailgun `template=` paraméterének párja.
 |
 | Miért verziózott: egy élő integráció sablonját nem lehet „csak úgy" átírni. Az új verzió
 | keletkezik, és külön döntés, hogy aktív legyen — így a küldés determinisztikus marad,
 | és a hibás verzió visszavonható.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('stored_template_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stored_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('body');
            $table->text('body_text')->nullable();
            $table->string('engine')->default('handlebars');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['stored_template_id', 'version']);
            $table->index(['workspace_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_template_versions');
        Schema::dropIfExists('stored_templates');
    }
};
