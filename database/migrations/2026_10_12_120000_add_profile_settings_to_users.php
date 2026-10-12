<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | v1.1 · SHELL — a felhasználó APP-SPECIFIKUS beállításai (D11).
 |
 | EXPAND-ONLY: kizárólag új, nullable oszlopok. A személyes adat, a jelszó és a 2FA a
 | ventSSO-ban marad — itt csak az él, ami a ventMail felületét szabja személyre.
 |
 | `locale_source` mondja meg, KI döntött a nyelvről: `sso` (a ventSSO `locale` claimje) vagy
 | `user` (a felhasználó maga választott). NULL = még senki. A `user` az SSO-nál erősebb:
 | utána a bejelentkezés sem írja felül.
 |
 | A `default_workspace_id` szándékosan NEM kaszkád: ha a workspace megszűnik, az oszlop
 | nullra áll, és a feloldó az első tagságra esik vissza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale_source', 8)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('date_format', 16)->nullable();
            $table->string('number_format', 16)->nullable();
            $table->jsonb('notification_prefs')->nullable();
            $table->foreignId('default_workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_workspace_id');
            $table->dropColumn(['locale_source', 'timezone', 'date_format', 'number_format', 'notification_prefs']);
        });
    }
};
