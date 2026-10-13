<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Domains\Actions\CreateDomain;
use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Jobs\VerifyDomainDns;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Testing\FakeDnsResolver;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Smtp\Models\SmtpCredential;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/*
 | v1.1 · 7. modul — Platform: domainek (élő DNS-állapot, Return-Path), API-kulcsok
 | (IP-allowlist, scope-alias, visszavont kulcsok), SMTP-hitelesítők (rotáció).
 */

function v11User(Workspace $workspace, string $role = 'owner'): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'p7-'.uniqid(),
        'name' => 'Fejlesztő',
        'email' => 'p7-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);

    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

    return $user;
}

function v11As(User $user, Workspace $workspace): mixed
{
    return test()->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid]);
}

/*
 | DOMAINEK
 */

it('a domain-lista a kiválasztott domain MÁSOLHATÓ rekordjait adja élő állapottal, privát kulcs nélkül', function () {
    $workspace = Workspace::factory()->create();
    $user = v11User($workspace);

    $domain = app(WorkspaceContext::class)->runFor($workspace, fn () => app(CreateDomain::class)('pelda.hu'));

    $response = v11As($user, $workspace)->get('/domains?domain='.$domain->uuid)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Domains/Index')
            ->has('domains', 1)
            ->where('selected.uuid', (string) $domain->uuid)
            ->has('selected.records', 6)
            ->where('selected.records.0.key', 'spf')
            ->where('selected.records.3.key', 'return_path_transactional')
            ->where('selected.records.5.key', 'tracking')
            ->where('selected.dkim.selector', (string) $domain->dkim_selector)
        );

    // A PRIVÁT KULCS sehol a válaszban.
    $response->assertDontSee('PRIVATE KEY');
});

it('a Return-Path CNAME élőben ellenőrződik, de a küldhetőséghez nem kell', function () {
    $workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($workspace);

    config()->set('sending.postal.pools.transactional.return_path_domain', 'rp-tx.ventmail.app');
    config()->set('sending.postal.pools.marketing.return_path_domain', 'rp-mk.ventmail.app');

    $resolver = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $resolver);

    $domain = app(CreateDomain::class)('pelda.hu');
    $records = $domain->dnsRecords();

    foreach (['spf', 'dkim', 'dmarc'] as $key) {
        $resolver->publish($records[$key]['name'], $records[$key]['type'], $records[$key]['value']);
    }

    app(VerifyDomainDns::class)->handle($domain);

    expect($domain->fresh()->status)->toBe('verified')
        ->and($domain->fresh()->return_path_status)->toBe('pending');

    $resolver->publish($records['return_path_transactional']['name'], 'CNAME', 'rp-tx.ventmail.app.');

    app(VerifyDomainDns::class)->handle($domain->fresh());

    expect($domain->fresh()->return_path_status)->toBe('verified')
        ->and($domain->fresh()->return_path_marketing_status)->toBe('pending');
});

it('az újraellenőrzés a listáról indítva a listára tér vissza, idegen domainre 404', function () {
    $workspace = Workspace::factory()->create();
    $user = v11User($workspace);
    app()->instance(DnsResolver::class, new FakeDnsResolver);

    $domain = app(WorkspaceContext::class)->runFor($workspace, fn () => app(CreateDomain::class)('pelda.hu'));

    v11As($user, $workspace)->post("/domains/{$domain->uuid}/verify", ['return' => 'index'])
        ->assertRedirect('/domains?domain='.$domain->uuid);

    $idegen = Workspace::factory()->create();
    $masik = app(WorkspaceContext::class)->runFor($idegen, fn () => app(CreateDomain::class)('masik.hu'));

    v11As($user, $workspace)->post("/domains/{$masik->uuid}/verify", ['return' => 'index'])->assertNotFound();
    v11As($user, $workspace)->post("/domains/{$masik->uuid}/dkim/rotate", ['return' => 'index'])->assertNotFound();

    // Idegen uuid a listán: a SAJÁT első domain jön, nem az idegen.
    v11As($user, $workspace)->get('/domains?domain='.$masik->uuid)
        ->assertInertia(fn ($page) => $page->where('selected.uuid', (string) $domain->uuid));
});

it('a DKIM-rotáció auditált, és a listára visszatér', function () {
    $workspace = Workspace::factory()->create();
    $user = v11User($workspace);

    $domain = app(WorkspaceContext::class)->runFor($workspace, fn () => app(CreateDomain::class)('pelda.hu'));
    $selector = $domain->dkim_selector;

    v11As($user, $workspace)->post("/domains/{$domain->uuid}/dkim/rotate", ['return' => 'index'])
        ->assertRedirect('/domains?domain='.$domain->uuid);

    $fresh = Domain::query()->withoutGlobalScopes()->find($domain->getKey());

    expect($fresh->dkim_selector)->not->toBe($selector)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('auditable_type', $fresh->getMorphClass())->where('action', 'updated')->exists())->toBeTrue();
});

/*
 | API-KULCSOK
 */

it('az IP-allowlist érvényes CIDR-listát vár', function () {
    $workspace = Workspace::factory()->create();
    $user = v11User($workspace);

    v11As($user, $workspace)->from('/api-keys')
        ->post('/api-keys', ['name' => 'CI', 'scopes' => ['messages:send'], 'ip_allowlist' => '203.0.113.0/24, nem-ip'])
        ->assertSessionHasErrors('ip_allowlist');

    v11As($user, $workspace)->from('/api-keys')
        ->post('/api-keys', ['name' => 'CI', 'scopes' => ['messages:send'], 'ip_allowlist' => '10.0.0.0/33'])
        ->assertSessionHasErrors('ip_allowlist');

    v11As($user, $workspace)->from('/api-keys')
        ->post('/api-keys', ['name' => 'CI', 'scopes' => ['messages:send'], 'ip_allowlist' => "203.0.113.0/24\n2001:db8::/32 198.51.100.17"])
        ->assertSessionHasNoErrors();

    $key = app(WorkspaceContext::class)->runFor($workspace, fn () => ApiKey::query()->first());

    expect($key->ip_allowlist)->toBe(['203.0.113.0/24', '2001:db8::/32', '198.51.100.17']);
});

it('az IP-allowlisten kívülről a kulcs ugyanazt a 401-et adja, mint a rossz kulcs', function () {
    $workspace = Workspace::factory()->create();

    ['plain' => $plain] = app(WorkspaceContext::class)->runFor(
        $workspace,
        fn () => ApiKey::issue('CI', ['events:read'], false, null, ['203.0.113.0/24']),
    );

    $kivul = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->withHeaders(['Authorization' => 'Bearer '.$plain])
        ->getJson('/v1/events');

    $kivul->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
        ->withHeaders(['Authorization' => 'Bearer '.$plain])
        ->getJson('/v1/events')
        ->assertOk();
});

it('a régi `validate` scope a `validate:run` kaput alias-ként nyitja (D14)', function () {
    $key = new ApiKey(['scopes' => ['validate']]);

    expect($key->hasScope('validate:run'))->toBeTrue()
        ->and($key->hasScope('validate'))->toBeTrue()
        ->and($key->hasScope('messages:send'))->toBeFalse()
        ->and((new ApiKey(['scopes' => ['validate:run']]))->hasScope('validate'))->toBeFalse();
});

it('a kiállítható scope-ok pontosan a /v1 kapui, és a visszavont kulcs is látszik', function () {
    $workspace = Workspace::factory()->create();
    $user = v11User($workspace);

    app(WorkspaceContext::class)->runFor($workspace, function (): void {
        ApiKey::issue('Régi', ['messages:send'])['model']->delete();
        ApiKey::issue('Élő', ['templates:read']);
    });

    v11As($user, $workspace)->get('/api-keys')
        ->assertInertia(fn ($page) => $page
            ->where('availableScopes', fn ($scopes) => collect($scopes)->contains('validate:run')
                && ! collect($scopes)->contains('validate')
                && collect($scopes)->contains('suppressions:write'))
            ->has('keys', 2)
            ->where('keys.0.revoked_at', null)
            ->where('keys.1.revoked_at', fn ($value) => $value !== null)
        );
});

it('a kulcs visszavonása auditált, és utána nem hitelesít', function () {
    $workspace = Workspace::factory()->create();
    $user = v11User($workspace);

    ['model' => $key, 'plain' => $plain] = app(WorkspaceContext::class)->runFor($workspace, fn () => ApiKey::issue('CI', ['events:read']));

    v11As($user, $workspace)->delete('/api-keys/'.$key->uuid)->assertRedirect();

    expect(AuditLog::query()->withoutGlobalScopes()->where('action', 'deleted')->where('auditable_type', $key->getMorphClass())->exists())->toBeTrue();

    $this->withHeaders(['Authorization' => 'Bearer '.$plain])->getJson('/v1/events')->assertStatus(401);
});

/*
 | SMTP-HITELESÍTŐK
 */

it('az SMTP-rotáció új jelszót ad EGYSZER, a régi azonnal visszavonva, és auditált', function () {
    $workspace = Workspace::factory()->create();
    $user = v11User($workspace, 'developer');

    $old = app(WorkspaceContext::class)->runFor($workspace, fn () => SmtpCredential::issue('CRM', null, 'transactional')['credential']);

    $response = v11As($user, $workspace)->from('/smtp-credentials')
        ->post("/smtp-credentials/{$old->uuid}/rotate");

    $response->assertRedirect('/smtp-credentials')->assertSessionHas('secret');

    $secret = session('secret');
    $new = SmtpCredential::query()->withoutGlobalScopes()->where('username', $secret['username'])->first();

    expect($new)->not->toBeNull()
        ->and($new->rotated_from_id)->toBe($old->getKey())
        ->and($new->name)->toBe('CRM')
        ->and(Hash::check($secret['password'], (string) $new->password_hash))->toBeTrue()
        // A jelszó NEM tárolódik nyersen.
        ->and($new->password_hash)->not->toBe($secret['password']);

    $regi = SmtpCredential::query()->withoutGlobalScopes()->withTrashed()->find($old->getKey());

    expect($regi->revoked_at)->not->toBeNull()
        ->and($regi->isUsable())->toBeFalse()
        ->and(AuditLog::query()->withoutGlobalScopes()->where('auditable_type', $new->getMorphClass())->where('action', 'created')->exists())->toBeTrue();

    // A visszavont hitelesítő már nem rotálható.
    v11As($user, $workspace)->post("/smtp-credentials/{$old->uuid}/rotate")->assertNotFound();
});

it('idegen tenant SMTP-hitelesítője nem rotálható, és az elemző nem rotálhat', function () {
    $workspace = Workspace::factory()->create();
    $idegen = Workspace::factory()->create();

    $credential = app(WorkspaceContext::class)->runFor($idegen, fn () => SmtpCredential::issue('X', null, 'transactional')['credential']);

    v11As(v11User($workspace), $workspace)->post("/smtp-credentials/{$credential->uuid}/rotate")->assertNotFound();

    $own = app(WorkspaceContext::class)->runFor($workspace, fn () => SmtpCredential::issue('Y', null, 'transactional')['credential']);

    v11As(v11User($workspace, 'analyst'), $workspace)->post("/smtp-credentials/{$own->uuid}/rotate")->assertForbidden();
});

it('az SMTP-képernyő a beküldés paramétereit is mutatja (plaintext port nélkül)', function () {
    $workspace = Workspace::factory()->create();

    v11As(v11User($workspace), $workspace)->get('/smtp-credentials')
        ->assertInertia(fn ($page) => $page
            ->where('submission.ports.0.port', 587)
            ->where('submission.ports.1.port', 465)
            ->has('submission.ports', 2)
        );
});
