<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | UI-polírozás · 6. pont — `templates.owner_campaign_id`. EXPAND-ONLY (nullable FK + index).
 |
 | A kampány HELYBEN készült tartalma egy sablon-sor, ami a kampányhoz tartozik: a küldőmotor
 | ugyanúgy a `campaigns.template_id`-n át éri el (egy motor), de a SABLONKÖNYVTÁR nem listázza
 | (ott csak az önálló sablonok vannak). NULL = önálló (könyvtári) sablon — minden meglévő sor
 | ilyen, tehát a régi viselkedés változatlan. A kampány törlésekor a hivatkozás NULL-ra áll
 | (a sablon soft-delete-tel él tovább, a verziótörténet megmarad).
 |
 | Az index NEM `CONCURRENTLY`: a `templates` workspace-enként néhány tucat soros tábla, a rövid
 | zár észrevehetetlen — a konkurens index-építés tranzakción kívüli futása itt csak kockázat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table): void {
            $table->foreignId('owner_campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->index(['workspace_id', 'owner_campaign_id']);
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'owner_campaign_id']);
            $table->dropConstrainedForeignId('owner_campaign_id');
        });
    }
};
