<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 | M0-2 elfogadási kritérium #11–#16 — a spec 25. fejezetének M0-releváns táblái.
 */

it('minden M0-tábla létrejön', function () {
    $tables = [
        'workspaces', 'workspace_parents', 'workspace_members',
        'api_keys', 'smtp_credentials',
        'domains', 'sending_identities',
        'ip_pools', 'ip_addresses',
        'suppressions', 'messages', 'message_events', 'audit_logs',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("hiányzó tábla: {$table}");
    }
});

it('a domains tábla a teljes hitelesítési állapotot tartja', function () {
    foreach ([
        'workspace_id', 'uuid', 'name', 'status',
        'spf_status', 'dkim_status', 'dmarc_status', 'ptr_status',
        'dkim_selector', 'dkim_public_key', 'dkim_private_key',
        'mta_sts_status', 'bimi_status', 'tracking_domain', 'tracking_status',
        'inbound_mx_status', 'verified_at',
    ] as $column) {
        expect(Schema::hasColumn('domains', $column))->toBeTrue("hiányzó domains oszlop: {$column}");
    }
});

it('az ip_addresses tábla warmup- és reputáció-állapotot tart', function () {
    foreach (['ip_pool_id', 'address', 'warmup_state', 'warmup_started_at', 'daily_limit',
        'reputation_score', 'status'] as $column) {
        expect(Schema::hasColumn('ip_addresses', $column))->toBeTrue("hiányzó ip_addresses oszlop: {$column}");
    }
});

it('a messages tábla a Mailgun-paritás mezőit tartja', function () {
    foreach (['workspace_id', 'uuid', 'channel', 'tags', 'variables', 'deliverytime',
        'testmode', 'ip_pool_id', 'idempotency_key', 'status'] as $column) {
        expect(Schema::hasColumn('messages', $column))->toBeTrue("hiányzó messages oszlop: {$column}");
    }
});

it('az api_keys és smtp_credentials csak hasht tárol', function () {
    expect(Schema::hasColumn('api_keys', 'key_hash'))->toBeTrue();
    expect(Schema::hasColumn('api_keys', 'key'))->toBeFalse('nyers API-kulcs oszlop!');

    expect(Schema::hasColumn('smtp_credentials', 'password_hash'))->toBeTrue();
    expect(Schema::hasColumn('smtp_credentials', 'password'))->toBeFalse('nyers SMTP-jelszó oszlop!');
});

it('a publikus entitásokon van uuid', function () {
    foreach (['domains', 'messages', 'api_keys', 'ip_pools', 'suppressions'] as $table) {
        expect(Schema::hasColumn($table, 'uuid'))->toBeTrue("hiányzó uuid: {$table}");
    }
});

it('a GDPR-releváns táblákon van soft delete', function () {
    foreach (['workspaces', 'domains', 'api_keys', 'smtp_credentials'] as $table) {
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue("hiányzó soft delete: {$table}");
    }
});

it('minden tenant-tábla workspace_id-hez kötött', function () {
    foreach (['domains', 'sending_identities', 'ip_pools', 'suppressions', 'messages',
        'api_keys', 'smtp_credentials', 'audit_logs'] as $table) {
        expect(Schema::hasColumn($table, 'workspace_id'))->toBeTrue("nincs workspace_id: {$table}");
    }
});
