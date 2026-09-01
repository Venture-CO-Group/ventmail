<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Actions\AssignWorkspaceRoles;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
 | „Minden admin-művelet auditált" (golden rule 11) — a legprivilegizáltabb művelet, a
 | szerep-kiosztás is. Enélkül valaki szerepet oszt magának, és nyoma sem marad.
 */

it('a szerep-kiosztás audit-sort ír', function () {
    $workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($workspace);

    $owner = User::factory()->create();
    $workspace->members()->create(['user_id' => $owner->id, 'roles' => [Role::Owner->value]]);
    Auth::login($owner);

    $target = User::factory()->create();
    app(AssignWorkspaceRoles::class)($target, [Role::Developer->value]);

    $log = AuditLog::query()->where('action', 'member.roles.assigned')->latest('id')->first();

    expect($log)->not->toBeNull('a szerep-kiosztás nem hagyott audit-nyomot');
    expect($log->user_id)->toBe($owner->id);
    expect($log->after)->toMatchArray(['roles' => [Role::Developer->value]]);
    expect($log->context)->toHaveKey('target_user_id');
});

it('a titok-szűrés a beágyazott szinteken is működik', function () {
    $workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($workspace);

    app(AuditLogger::class)->log('smtp.credential.rotated', context: [
        'request' => [
            'password' => 'NYERS-JELSZO-123',
            'nested' => ['api_key' => 'vm_live_TITOK', 'username' => 'lathato'],
        ],
    ]);

    $log = AuditLog::query()->latest('id')->firstOrFail();
    $serialized = json_encode($log->context);

    expect($serialized)->not->toContain('NYERS-JELSZO-123');
    expect($serialized)->not->toContain('vm_live_TITOK');
    expect($serialized)->toContain('lathato');
    // A változás TÉNYE látszik, csak az érték nem.
    expect($serialized)->toContain('[redacted]');
});

it('az aktor akkor is azonosítható marad, ha a felhasználót törlik', function () {
    $workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($workspace);

    $user = User::factory()->create(['email' => 'aktor@example.test']);
    Auth::login($user);

    app(AuditLogger::class)->log('teszt.muvelet');

    $log = AuditLog::query()->latest('id')->firstOrFail();

    expect($log->actor_email)->toBe('aktor@example.test');
    expect($log->actor_sso_subject)->toBe($user->sso_subject);
});

it('az audit-olvasás workspace-re szűkíthető', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    app(WorkspaceContext::class)->set($a);
    app(AuditLogger::class)->log('a.muvelet');

    app(WorkspaceContext::class)->set($b);
    app(AuditLogger::class)->log('b.muvelet');

    expect(AuditLog::forWorkspace($a)->pluck('action')->all())->toBe(['a.muvelet']);
    expect(AuditLog::forWorkspace($b)->pluck('action')->all())->toBe(['b.muvelet']);
});

it('a hosszú user-agent nem dönti el a műveletet', function () {
    // Bájt szerinti vágás többbájtos karakter közepén érvénytelen UTF-8-at adna,
    // és a Postgres 22021 hibával az EGÉSZ admin-műveletet elszalasztaná.
    $workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($workspace);

    $this->withServerVariables(['HTTP_USER_AGENT' => str_repeat('á', 300)]);

    $log = app(AuditLogger::class)->log('teszt.muvelet');

    expect($log->exists)->toBeTrue();
    expect(mb_check_encoding((string) $log->user_agent, 'UTF-8'))->toBeTrue();
});
