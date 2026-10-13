<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · 7. modul (Platform) — domainek, API-kulcsok. EXPAND-ONLY.
 |
 | - `domains.return_path_status` / `return_path_marketing_status`: a Return-Path CNAME élő
 |   ellenőrzése (D169). A `verified` státuszhoz NEM tartozik hozzá (a PreSendGate 1. kapuja
 |   változatlan: SPF + DKIM + DMARC) — a bounce-visszavezetés és a DMARC SPF-igazítás
 |   állapotát mutatja.
 | - `api_keys.ip_allowlist`: CIDR-lista (D14). NULL / üres = bármely cím.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('domains', 'return_path_status')) {
            Schema::table('domains', function (Blueprint $table): void {
                $table->string('return_path_status', 16)->default('pending');
                $table->string('return_path_marketing_status', 16)->default('pending');
            });
        }

        if (! Schema::hasColumn('api_keys', 'ip_allowlist')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->jsonb('ip_allowlist')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn('ip_allowlist');
        });

        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn(['return_path_status', 'return_path_marketing_status']);
        });
    }
};
