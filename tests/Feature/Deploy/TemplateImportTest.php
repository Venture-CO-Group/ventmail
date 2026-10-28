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

/*
 | FO1 — a `<név>.subject` fájl (egyetlen sor) a sablonverzió tárgya. A tartalom — a tárgyat is
 | beleértve — változása új AKTÍV verziót hoz; egyébként idempotens.
 */
function subjectSet(array $files): string
{
    $set = 'zz-subject-'.bin2hex(random_bytes(3));
    $dir = resource_path("mail-templates/{$set}");
    mkdir($dir, 0777, true);

    foreach ($files as $file => $content) {
        file_put_contents("{$dir}/{$file}", $content);
    }

    return $set;
}

function removeSet(string $set): void
{
    $dir = resource_path("mail-templates/{$set}");
    array_map('unlink', glob("{$dir}/*") ?: []);
    @rmdir($dir);
}

function activeSubject(string $name): ?string
{
    return StoredTemplateVersion::query()
        ->whereHas('template', fn ($q) => $q->where('name', $name))
        ->where('is_active', true)->value('subject');
}

it('a .subject fájl tartalma a verzió tárgya (első sor, trimmelve)', function () {
    $set = subjectSet(['x-a-hu.html' => '<p>a</p>', 'x-a-hu.subject' => "  Rendelés {{ order_no }}  \n", 'x-b-hu.html' => '<p>b</p>']);

    try {
        expect(Artisan::call('ventmail:templates:import', ['workspace' => 'venture', 'set' => $set]))->toBe(0);
        app(WorkspaceContext::class)->set($this->workspace);

        expect(activeSubject('x-a-hu'))->toBe('Rendelés {{ order_no }}')
            ->and(activeSubject('x-b-hu'))->toBeNull();
    } finally {
        removeSet($set);
    }
});

it('tárgy-változás új AKTÍV verziót hoz, változatlan tárgy nem', function () {
    $set = subjectSet(['x-a-hu.html' => '<p>a</p>', 'x-a-hu.subject' => 'Egy']);

    try {
        Artisan::call('ventmail:templates:import', ['workspace' => 'venture', 'set' => $set]);
        Artisan::call('ventmail:templates:import', ['workspace' => 'venture', 'set' => $set]);
        app(WorkspaceContext::class)->set($this->workspace);
        $template = StoredTemplate::query()->where('name', 'x-a-hu')->firstOrFail();
        expect($template->versions()->count())->toBe(1);
        app(WorkspaceContext::class)->forget();

        file_put_contents(resource_path("mail-templates/{$set}/x-a-hu.subject"), 'Kettő');
        Artisan::call('ventmail:templates:import', ['workspace' => 'venture', 'set' => $set]);

        app(WorkspaceContext::class)->set($this->workspace);
        expect($template->versions()->count())->toBe(2)
            ->and($template->versions()->where('is_active', true)->count())->toBe(1)
            ->and(activeSubject('x-a-hu'))->toBe('Kettő');
    } finally {
        removeSet($set);
    }
});

it('a tárgy-fájl eltávolítása (törzs változatlan) új, tárgy nélküli verziót hoz', function () {
    $set = subjectSet(['x-a-hu.html' => '<p>a</p>', 'x-a-hu.subject' => 'Egy']);

    try {
        Artisan::call('ventmail:templates:import', ['workspace' => 'venture', 'set' => $set]);
        unlink(resource_path("mail-templates/{$set}/x-a-hu.subject"));
        Artisan::call('ventmail:templates:import', ['workspace' => 'venture', 'set' => $set]);

        app(WorkspaceContext::class)->set($this->workspace);
        expect(activeSubject('x-a-hu'))->toBeNull()
            ->and(StoredTemplate::query()->where('name', 'x-a-hu')->firstOrFail()->versions()->count())->toBe(2);
    } finally {
        removeSet($set);
    }
});

it('a ventPay üzemeltetői riasztása pontosan azon a néven kerül be, amelyen a ventPay küldi', function () {
    // ventPay SendOperatorAlert: `template` = `ventpay.operator_alert`, nyelvi utótag nélkül,
    // `v:alert_kind`, `v:alert_name`, `v:reference`, `v:tenant_id`, `v:mode`, `v:occurred_at`, `v:admin_url`.
    expect(Artisan::call('ventmail:templates:import', ['workspace' => 'venture', 'set' => 'ventpay']))->toBe(0);

    app(WorkspaceContext::class)->set($this->workspace);

    $version = StoredTemplateVersion::query()
        ->whereHas('template', fn ($q) => $q->where('name', 'ventpay.operator_alert'))
        ->where('is_active', true)->first();

    expect($version)->not->toBeNull()
        ->and($version->subject)->toBe('[ventPay] {{ alert_name }} ({{ mode || deployment }})')
        ->and($version->body)->toContain('href="{{ admin_url }}"')
        ->and($version->body)->toContain('lang="hu"')
        ->and($version->body)->toContain('lang="en"')
        ->and($version->body_text)->toContain('{{ alert_name }}');

    foreach (['alert_kind', 'alert_name', 'reference', 'tenant_id', 'mode', 'occurred_at'] as $var) {
        expect($version->body)->toContain('{{ '.$var);
    }
});
