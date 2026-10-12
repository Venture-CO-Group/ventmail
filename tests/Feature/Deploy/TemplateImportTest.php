<?php

declare(strict_types=1);

use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
 | A ventSSO auth-sablonjai (deploy-ovh 6.): verifikáció, jelszó-reset, magic link, 2FA-kód,
 | biztonsági értesítés, meghívó — HU és EN. A ventSSO driver `sso-<trigger>-<locale>`
 | néven hivatkozik rájuk. Az import idempotens: változatlan törzsre nem ír új verziót,
 | változottra új AKTÍV verziót.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create(['slug' => 'venture']);
});

function activeBody(string $name): ?string
{
    return StoredTemplateVersion::query()
        ->whereHas('template', fn ($q) => $q->where('name', $name))
        ->where('is_active', true)->value('body');
}

it('mind a hat trigger mindkét nyelven bekerül, a ventSSO által várt néven', function () {
    expect(Artisan::call('ventmail:templates:import', ['workspace' => 'venture', 'set' => 'ventsso']))->toBe(0);

    app(WorkspaceContext::class)->set($this->workspace);

    foreach (['email_verification', 'password_reset', 'magic_link', 'email_otp', 'security_alert', 'invitation'] as $trigger) {
        foreach (['hu', 'en'] as $locale) {
            expect(activeBody("sso-{$trigger}-{$locale}"))->not->toBeNull("hiányzik: sso-{$trigger}-{$locale}");
        }
    }

    expect(activeBody('sso-password_reset-hu'))->toContain('{{ url }}')
        ->and(activeBody('sso-email_otp-en'))->toContain('{{ code }}');
});

it('idempotens: változatlan törzsre nem ír új verziót', function () {
    Artisan::call('ventmail:templates:import', ['workspace' => 'venture', 'set' => 'ventsso']);
    Artisan::call('ventmail:templates:import', ['workspace' => 'venture', 'set' => 'ventsso']);

    app(WorkspaceContext::class)->set($this->workspace);

    expect(StoredTemplate::query()->count())->toBe(12);
    StoredTemplate::query()->get()->each(fn (StoredTemplate $t) => expect($t->versions()->count())->toBe(1));
});

it('eltérő élő tartalomra új AKTÍV verziót hoz, és pontosan egy verzió aktív', function () {
    app(WorkspaceContext::class)->set($this->workspace);
    $old = StoredTemplate::create(['name' => 'sso-invitation-en', 'description' => 'kézi']);
    StoredTemplateVersion::create(['stored_template_id' => $old->id, 'version' => 1, 'body' => '<p>régi</p>', 'is_active' => true]);
    app(WorkspaceContext::class)->forget();

    Artisan::call('ventmail:templates:import', ['workspace' => 'venture', 'set' => 'ventsso']);

    app(WorkspaceContext::class)->set($this->workspace);
    expect($old->versions()->count())->toBe(2)
        ->and($old->versions()->where('is_active', true)->count())->toBe(1)
        ->and(activeBody('sso-invitation-en'))->toContain('{{ organization }}');
});

it('ismeretlen sablonkészletre hibával áll meg', function () {
    expect(Artisan::call('ventmail:templates:import', ['workspace' => 'venture', 'set' => '../etc']))->not->toBe(0);
});
