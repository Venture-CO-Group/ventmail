<?php

declare(strict_types=1);

use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Models\SmtpCredential;
use App\Modules\Smtp\Services\CredentialAuthenticator;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\VentSec\Contracts\VentSecEvents;
use App\Modules\VentSec\Testing\FakeVentSecEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | FO2 — `smtp.auth_failed` a ventSec katalógusából: a sikertelen submission-hitelesítés
 | természetes helyén (CredentialAuthenticator). Csak a listener és egy zárt okkód megy ki —
 | felhasználónév, jelszó, IP SOHA.
 */

beforeEach(function () {
    $this->events = new FakeVentSecEvents;
    $this->app->instance(VentSecEvents::class, $this->events);

    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    ['username' => $this->username, 'password' => $this->password] = SmtpCredential::issue('CI');
});

it('rossz jelszóra és ismeretlen felhasználóra azonos okkóddal kibocsátja', function () {
    $auth = app(CredentialAuthenticator::class);

    expect($auth->authenticate($this->username, 'rossz-jelszo', ChannelSecurity::encrypted('TLSv1.3')))->toBeNull()
        ->and($auth->authenticate('nincs-ilyen', 'x', ChannelSecurity::encrypted('TLSv1.3')))->toBeNull();

    expect($this->events->emitted)->toHaveCount(2);

    foreach ($this->events->emitted as $event) {
        expect($event['action'])->toBe('smtp.auth_failed')
            ->and($event['outcome'])->toBe('failure')
            ->and($event['context'])->toBe(['listener' => 'submission', 'reason' => 'invalid_credentials']);
    }
});

it('titkosítatlan csatornán és visszavont hitelesítőnél a saját okkódjával', function () {
    $auth = app(CredentialAuthenticator::class);

    $auth->authenticate($this->username, $this->password, ChannelSecurity::plaintext());

    SmtpCredential::query()->update(['revoked_at' => now()]);
    $auth->authenticate($this->username, $this->password, ChannelSecurity::encrypted('TLSv1.3'));

    expect(array_column(array_column($this->events->emitted, 'context'), 'reason'))
        ->toBe(['plaintext_channel', 'credential_unusable']);
});

it('sikeres hitelesítésre nem bocsát ki eseményt', function () {
    app(CredentialAuthenticator::class)->authenticate($this->username, $this->password, ChannelSecurity::encrypted('TLSv1.3'));

    expect($this->events->emitted)->toBe([]);
});

it('a felhasználónév és a jelszó sehol nincs az eseményben', function () {
    app(CredentialAuthenticator::class)->authenticate($this->username, 'rossz-jelszo-123', ChannelSecurity::encrypted('TLSv1.3'));

    $dump = json_encode($this->events->emitted);

    expect($dump)->not->toContain($this->username)->not->toContain('rossz-jelszo-123');
});

it('a kibocsátó hibája nem töri meg a hitelesítést', function () {
    $this->app->instance(VentSecEvents::class, new class extends FakeVentSecEvents
    {
        public function emit(string $action, string $outcome, array $context = []): void
        {
            throw new RuntimeException('ventsec down');
        }
    });

    $auth = app(CredentialAuthenticator::class);

    expect($auth->authenticate($this->username, 'rossz', ChannelSecurity::encrypted('TLSv1.3')))->toBeNull()
        ->and($auth->authenticate($this->username, $this->password, ChannelSecurity::encrypted('TLSv1.3')))->not->toBeNull();
});
