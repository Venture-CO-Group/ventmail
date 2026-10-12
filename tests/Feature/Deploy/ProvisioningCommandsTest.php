<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
 | Az éles gép provisioningja CLI-ből (deploy-ovh 4. és 6.).
 |
 | A Venture rendszer-workspace, a küldő domain, az API-kulcsok és a két platform IP-pool
 | felülete a deploy után még nem érhető el (nincs bejelentkezett tulajdonos) — ezért
 | parancs hozza létre őket. Mind idempotens, mind auditált, és a plaintext API-kulcs
 | KIZÁRÓLAG a stdout-ra megy (csőbe), soha nem naplóba.
 */

function runCommand(string $command, array $args = []): array
{
    $code = Artisan::call($command, $args);

    return [$code, Artisan::output()];
}

it('a rendszer-workspace létrehozása idempotens', function () {
    [$code] = runCommand('ventmail:workspace:ensure', ['slug' => 'venture', '--name' => 'Venture']);
    [$again] = runCommand('ventmail:workspace:ensure', ['slug' => 'venture', '--name' => 'Venture']);

    expect($code)->toBe(0)->and($again)->toBe(0);
    expect(Workspace::query()->where('slug', 'venture')->count())->toBe(1);
});

it('a küldő domaint DKIM-kulccsal veszi fel, és kiírja a DNS-rekordokat — privát kulcs nélkül', function () {
    runCommand('ventmail:workspace:ensure', ['slug' => 'venture', '--name' => 'Venture']);

    [$code, $out] = runCommand('ventmail:domain:add', ['workspace' => 'venture', 'domain' => 'notify.pelda.hu']);

    $workspace = Workspace::query()->where('slug', 'venture')->firstOrFail();
    app(WorkspaceContext::class)->set($workspace);
    $domain = Domain::query()->where('name', 'notify.pelda.hu')->firstOrFail();

    expect($code)->toBe(0)
        ->and($out)->toContain($domain->dkim_selector.'._domainkey.notify.pelda.hu')
        ->and($out)->toContain('psrp-tx.notify.pelda.hu')
        ->and($out)->not->toContain('PRIVATE KEY');

    // Másodszor sem hoz létre újat (és nem forgat kulcsot).
    runCommand('ventmail:domain:add', ['workspace' => 'venture', 'domain' => 'notify.pelda.hu']);
    expect(Domain::query()->where('name', 'notify.pelda.hu')->count())->toBe(1)
        ->and(Domain::query()->where('name', 'notify.pelda.hu')->value('dkim_selector'))->toBe($domain->dkim_selector);
});

it('az API-kulcs plaintextje csak a stdout-on jelenik meg, a megadott scope-okkal; a visszavonás törli', function () {
    runCommand('ventmail:workspace:ensure', ['slug' => 'venture', '--name' => 'Venture']);

    [$code, $out] = runCommand('ventmail:api-key:issue', [
        'workspace' => 'venture', 'name' => 'ventsso', '--scope' => ['messages:send', 'templates:read'],
    ]);
    $plain = trim($out);

    expect($code)->toBe(0)->and($plain)->toStartWith('vm_live_');

    $key = ApiKey::findByPlainKey($plain);
    expect($key)->not->toBeNull()
        ->and($key->scopes)->toBe(['messages:send', 'templates:read'])
        ->and($key->channels)->toBe(['transactional']);

    // A plaintext nem került az audit-naplóba.
    expect(AuditLog::query()->withoutGlobalScopes()->get()->toJson())->not->toContain($plain);

    [$again] = runCommand('ventmail:api-key:issue', ['workspace' => 'venture', 'name' => 'ventsso', '--scope' => ['messages:send']]);
    expect($again)->not->toBe(0, 'azonos nevű élő kulcs nem adható ki újra');

    [$revoked] = runCommand('ventmail:api-key:revoke', ['workspace' => 'venture', 'name' => 'ventsso']);
    expect($revoked)->toBe(0)->and(ApiKey::findByPlainKey($plain))->toBeNull();
});

it('a két platform pool pontosan két IP-vel, külön osztállyal jön létre — a marketing keret 0', function () {
    [$code] = runCommand('ventmail:ip-pools:sync', [
        '--transactional' => '192.0.2.10', '--transactional-hostname' => 'mta-tx1.pelda.test',
        '--marketing' => '192.0.2.20', '--marketing-hostname' => 'mta-mk1.pelda.test',
        '--transactional-daily-limit' => 200,
    ]);
    runCommand('ventmail:ip-pools:sync', [
        '--transactional' => '192.0.2.10', '--transactional-hostname' => 'mta-tx1.pelda.test',
        '--marketing' => '192.0.2.20', '--marketing-hostname' => 'mta-mk1.pelda.test',
        '--transactional-daily-limit' => 200,
    ]);

    expect($code)->toBe(0);

    $pools = IpPool::onPlatform(fn () => IpPool::query()->whereNull('workspace_id')->with('addresses')->get()->keyBy('channel'));

    expect($pools->keys()->sort()->values()->all())->toBe(['marketing', 'transactional'])
        ->and($pools['transactional']->addresses->pluck('address')->all())->toBe(['192.0.2.10'])
        ->and($pools['transactional']->addresses->first()->hostname)->toBe('mta-tx1.pelda.test')
        ->and($pools['transactional']->addresses->first()->daily_limit)->toBe(200)
        ->and($pools['marketing']->addresses->pluck('address')->all())->toBe(['192.0.2.20'])
        ->and($pools['marketing']->addresses->first()->daily_limit)->toBe(0);
    expect(IpAddress::query()->count())->toBe(2);
});

it('ugyanaz az IP nem lehet mindkét poolban', function () {
    [$code] = runCommand('ventmail:ip-pools:sync', [
        '--transactional' => '192.0.2.10', '--transactional-hostname' => 'a.test',
        '--marketing' => '192.0.2.10', '--marketing-hostname' => 'b.test',
    ]);

    expect($code)->not->toBe(0)->and(IpPool::onPlatform(fn () => IpPool::query()->count()))->toBe(0);
});

it('tagot csak már belépett (létező) felhasználóhoz rendel, a megadott szereppel', function () {
    runCommand('ventmail:workspace:ensure', ['slug' => 'venture', '--name' => 'Venture']);

    [$missing] = runCommand('ventmail:member:grant', ['workspace' => 'venture', 'email' => 'nincs@pelda.test', 'role' => 'owner']);
    expect($missing)->not->toBe(0);

    $user = User::factory()->create(['email' => 'tulaj@pelda.test']);
    [$code] = runCommand('ventmail:member:grant', ['workspace' => 'venture', 'email' => 'tulaj@pelda.test', 'role' => 'owner']);

    expect($code)->toBe(0);
    $member = WorkspaceMember::query()->where('user_id', $user->id)->firstOrFail();
    expect($member->roles())->toBe(['owner']);

    [$revoke] = runCommand('ventmail:member:revoke', ['workspace' => 'venture', 'email' => 'tulaj@pelda.test']);
    expect($revoke)->toBe(0)->and(WorkspaceMember::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
