<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Tenancy: a workspace a ventMail tenant-egysége. A tagság a ventMail SAJÁT
 | workspace_members táblájából dől el — a ventSSO tokenből SOHA (golden rule 1, 6).
 | Sub-account: parent ↔ child kapcsolat a workspace_parents táblában.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();          // publikus azonosító
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('plan')->default('free');
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
            $table->softDeletes();                    // GDPR-releváns: nem hard delete
        });

        Schema::create('workspace_parents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('child_workspace_id')->constrained('workspaces')->cascadeOnDelete();
            // A parent explicit jogosultságai a child felett (nyers adat csak itt engedve).
            $table->jsonb('permissions')->default('{}');
            $table->timestamps();

            $table->unique(['parent_workspace_id', 'child_workspace_id']);
            $table->unique('child_workspace_id'); // egy childnak legfeljebb egy parentje van
        });

        Schema::create('workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->jsonb('roles')->default('[]');   // owner|admin|developer|editor|author|analyst
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspace_parents');
        Schema::dropIfExists('workspaces');
    }
};
