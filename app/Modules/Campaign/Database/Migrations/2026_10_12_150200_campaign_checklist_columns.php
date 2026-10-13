<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · Studio — kampány checklist és feladó-variáns. EXPAND-ONLY (nullable oszlopok).
 |
 | - `campaigns.send_mode` (`now` | `later`) és `scheduled_local` („2026-10-16 09:00", a
 |   kampány `timezone`-jában): a checklist ütemezés-szakasza ezt rögzíti. A tényleges
 |   ütemezés továbbra is a `CampaignLifecycle::schedule()` (→ `scheduled_at` UTC).
 | - `campaign_variants.from_email` / `from_name`: FELADÓ-variáns az A/B teszthez. NULL = a
 |   kampány feladója megy (a v1.1 előtti variánsok viselkedése változatlan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('send_mode', 8)->nullable();
            $table->string('scheduled_local', 16)->nullable();
        });

        Schema::table('campaign_variants', function (Blueprint $table): void {
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_variants', function (Blueprint $table): void {
            $table->dropColumn(['from_email', 'from_name']);
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn(['send_mode', 'scheduled_local']);
        });
    }
};
