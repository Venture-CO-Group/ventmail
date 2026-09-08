<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A6 · Kézbesíthetőségi suite — séma.
 |
 | Négy tábla, négy külön kérdésre:
 |  - `blocklist_checks`: feketelistán vagyunk-e (43.);
 |  - `dmarc_reports` + `dmarc_report_rows`: ki küld a nevünkben (44.);
 |  - `seed_tests`: hova érkezik a levél — inbox, spam, promóciók (45.).
 |
 | A `blocklist_checks` és a `dmarc_reports` NEM workspace-hez kötött oszlopon szűr elsőként:
 | a blocklist a MI IP-nkről szól (közös infrastruktúra), a DMARC viszont a tenant domainjéről
 | — utóbbi tehát tenant-adat, az előbbi platform-adat. A kettőt szándékosan külön kezeljük.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocklist_checks', function (Blueprint $table): void {
            $table->id();
            // A KÜLDŐ IP a mi infrastruktúránk: a találat MINDEN tenantot érint, aki arról
            // az IP-ről küld. Ezért nincs `workspace_id` — a super-admin (C2) nézi.
            $table->foreignId('ip_address_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('target');
            $table->string('zone');
            $table->boolean('listed')->default(false);
            $table->string('detail')->nullable();
            $table->timestampTz('checked_at');
            $table->timestamps();

            $table->index(['target', 'checked_at']);
            $table->index(['listed', 'checked_at']);
        });

        Schema::create('dmarc_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();

            $table->string('org_name')->nullable();
            $table->string('report_id')->nullable();
            $table->string('policy')->nullable();
            $table->timestampTz('range_start')->nullable();
            $table->timestampTz('range_end')->nullable();

            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('passed')->default(0);
            $table->unsignedBigInteger('failed')->default(0);

            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            // Ugyanaz a jelentés kétszer is megérkezhet (a fogadók újraküldenek).
            $table->unique(['workspace_id', 'org_name', 'report_id'], 'dmarc_reports_unique');
            $table->index(['workspace_id', 'range_start']);
        });

        Schema::create('dmarc_report_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dmarc_report_id')->constrained()->cascadeOnDelete();

            $table->string('source_ip');
            $table->unsignedBigInteger('count')->default(0);
            $table->string('disposition')->nullable();
            $table->string('spf_result')->nullable();
            $table->string('dkim_result')->nullable();
            $table->string('header_from')->nullable();

            // A MI IP-nk vagy idegen? Az utóbbi vagy egy elfelejtett saját rendszer, vagy
            // egy hamisító — mindkettő azonnali figyelmet érdemel.
            $table->boolean('known_source')->default(false);

            $table->timestamps();

            $table->index(['workspace_id', 'dmarc_report_id']);
            $table->index(['dmarc_report_id', 'known_source']);
        });

        Schema::create('seed_tests', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name')->nullable();
            $table->string('status')->default('pending');

            $table->unsignedInteger('inbox')->default(0);
            $table->unsignedInteger('spam')->default(0);
            $table->unsignedInteger('promotions')->default(0);
            $table->unsignedInteger('missing')->default(0);

            $table->jsonb('providers')->default('{}');

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seed_tests');
        Schema::dropIfExists('dmarc_report_rows');
        Schema::dropIfExists('dmarc_reports');
        Schema::dropIfExists('blocklist_checks');
    }
};
