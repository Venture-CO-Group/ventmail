<?php

declare(strict_types=1);

use App\Modules\Analytics\Models\WorkspaceBranding;
use App\Modules\Analytics\Services\BrandingService;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AnalyticsFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 8. modul (D233, D236) — workspace-branding: jogosultság, logó-ellenőrzés (közös
 | ImageInspector), szín + WCAG-kontraszt, szöveg-tisztítás, audit, tenant-izolált logó.
 */

beforeEach(fn () => Storage::fake('local'));

function vmImage(string $type, int $w, int $h): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 60, 200));
    ob_start();
    match ($type) {
        'png' => imagepng($image),
        'jpg' => imagejpeg($image),
        'webp' => imagewebp($image),
    };

    return (string) ob_get_clean();
}

function vmUpload($test, $user, $workspace, string $bytes, string $name = 'logo.png')
{
    $path = tempnam(sys_get_temp_dir(), 'logo');
    file_put_contents($path, $bytes);

    return $test->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/settings/branding')
        ->post('/settings/branding/logo', ['logo' => new UploadedFile($path, $name, null, null, true)]);
}

it('az owner és az admin láthatja és mentheti; az editor és az analyst 403', function (string $role, bool $allowed) {
    [$workspace, $user] = AnalyticsFixtures::tenant($role);

    $show = $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->get('/settings/branding');
    $save = $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->from('/settings/branding')
        ->put('/settings/branding', ['primary_color' => '#0E1322', 'company_name' => 'Nádor', 'footer_text' => 'Bizalmas']);

    if ($allowed) {
        $show->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Analytics/Branding')->has('branding')->has('limits'));
        $save->assertRedirect('/settings/branding')->assertSessionHasNoErrors();
        expect(WorkspaceBranding::query()->sole()->primary_color)->toBe('#0E1322')
            ->and(AuditLog::query()->where('action', 'branding.updated')->exists())->toBeTrue();
    } else {
        $show->assertForbidden();
        $save->assertForbidden();
        expect(WorkspaceBranding::query()->count())->toBe(0);
    }
})->with([['owner', true], ['admin', true], ['editor', false], ['analyst', false]]);

it('szín: hex-alak és WCAG-kontraszt; vezérlőkarakter tiltva; üres = alapértelmezés', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant();
    $put = fn (array $data) => $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->from('/settings/branding')->put('/settings/branding', $data);

    $put(['primary_color' => 'red'])->assertSessionHasErrors('primary_color');
    $put(['primary_color' => '#12345'])->assertSessionHasErrors('primary_color');
    // #777777: sem a fehér, sem a sötét borítószöveg nem éri el a 4,5 : 1-et.
    $put(['primary_color' => '#777777'])->assertSessionHasErrors('primary_color');
    $put(['company_name' => "Nádor\x07Kft"])->assertSessionHasErrors('company_name');
    // Irány-felülíró (RLO / isolate) karakter sem kerülhet a borítóra (D270).
    $put(['company_name' => "Nádor \u{202E}tfK"])->assertSessionHasErrors('company_name');
    $put(['footer_text' => "Bizalmas \u{2066}x\u{2069}"])->assertSessionHasErrors('footer_text');
    $put(['footer_text' => str_repeat('x', 301)])->assertSessionHasErrors('footer_text');

    $put(['primary_color' => '#5b7cff', 'company_name' => '  Nádor  ', 'footer_text' => "Első sor\nMásodik sor"])->assertSessionHasNoErrors();
    $row = WorkspaceBranding::query()->sole();
    expect($row->primary_color)->toBe('#5B7CFF')->and($row->company_name)->toBe('Nádor')->and($row->footer_text)->toBe("Első sor\nMásodik sor");

    $put(['primary_color' => '', 'company_name' => '', 'footer_text' => ''])->assertSessionHasNoErrors();
    expect(WorkspaceBranding::query()->sole()->primary_color)->toBeNull();
});

it('logó: PNG/JPEG/WebP elfogadva, privát diszken, audit; SVG elutasítva', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant();
    RateLimiter::clear('branding-logo');

    vmUpload($this, $user, $workspace, vmImage('png', 200, 100))->assertSessionHasNoErrors();
    $row = WorkspaceBranding::query()->sole();
    expect($row->logo_path)->toBe('branding/'.$workspace->uuid.'/logo.png')->and($row->logo_width)->toBe(200);
    Storage::disk('local')->assertExists($row->logo_path);
    expect(AuditLog::query()->where('action', 'branding.logo_uploaded')->exists())->toBeTrue();

    vmUpload($this, $user, $workspace, vmImage('webp', 100, 100), 'logo.webp')->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing('branding/'.$workspace->uuid.'/logo.png');
    Storage::disk('local')->assertExists('branding/'.$workspace->uuid.'/logo.webp');

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><script>alert(1)</script></svg>';
    vmUpload($this, $user, $workspace, $svg, 'logo.svg')->assertSessionHasErrors('logo');
});

it('logó: méret, képméret, képarány', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant();

    vmUpload($this, $user, $workspace, vmImage('png', 40, 40))->assertSessionHasErrors('logo');
    vmUpload($this, $user, $workspace, vmImage('png', 2100, 300))->assertSessionHasErrors('logo');
    vmUpload($this, $user, $workspace, vmImage('png', 900, 100))->assertSessionHasErrors('logo');
    vmUpload($this, $user, $workspace, str_repeat('a', 1024 * 1024 + 1), 'logo.png')->assertSessionHasErrors('logo');

    expect(WorkspaceBranding::query()->count())->toBe(0);
});

it('logó: a polyglot hasznos teher és az EXIF nem éli túl az újrakódolást', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant();

    // PNG + a fájl végére fűzött HTML/PHP hasznos teher.
    vmUpload($this, $user, $workspace, vmImage('png', 120, 120).'<?php system($_GET["c"]); ?><script>alert(1)</script>')->assertSessionHasNoErrors();
    $stored = Storage::disk('local')->get(WorkspaceBranding::query()->sole()->logo_path);
    expect($stored)->not->toContain('<?php')->not->toContain('<script>');

    // JPEG + APP1/Exif szegmens titkos GPS-szöveggel.
    $jpeg = vmImage('jpg', 120, 120);
    $exif = "Exif\0\0GPS-TITOK-47.4979N";
    $withExif = "\xFF\xD8\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    vmUpload($this, $user, $workspace, $withExif, 'logo.jpg')->assertSessionHasNoErrors();
    $stored = Storage::disk('local')->get(WorkspaceBranding::query()->sole()->logo_path);
    expect($stored)->not->toContain('GPS-TITOK')->and(WorkspaceBranding::query()->sole()->logo_mime)->toBe('image/jpeg');
});

it('a logó-kiszolgálás csak a saját workspace logóját adja, nosniff + private; eltávolítás auditált', function () {
    [$foreign, $foreignUser] = AnalyticsFixtures::tenant();
    vmUpload($this, $foreignUser, $foreign, vmImage('png', 100, 100))->assertSessionHasNoErrors();

    [$workspace, $user] = AnalyticsFixtures::tenant('analyst');
    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->get('/settings/branding/logo')->assertNotFound();

    app(WorkspaceContext::class)->set($foreign);
    $this->actingAs($foreignUser)->withSession(['workspace_uuid' => $foreign->uuid])->get('/settings/branding/logo')
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'max-age=300, private');

    $this->actingAs($foreignUser)->withSession(['workspace_uuid' => $foreign->uuid])->from('/settings/branding')->delete('/settings/branding/logo')->assertSessionHasNoErrors();
    expect(WorkspaceBranding::query()->sole()->logo_path)->toBeNull()
        ->and(AuditLog::query()->where('action', 'branding.logo_removed')->exists())->toBeTrue();
    Storage::disk('local')->assertMissing('branding/'.$foreign->uuid.'/logo.png');
});

it('a sub-account NEM örököl brandinget a parenttől', function () {
    [$parent, $user] = AnalyticsFixtures::tenant();
    WorkspaceBranding::query()->create(['primary_color' => '#0E1322', 'company_name' => 'Parent Kft']);

    $child = Workspace::factory()->create();
    DB::table('workspace_parents')->insert(['parent_workspace_id' => $parent->getKey(), 'child_workspace_id' => $child->getKey(), 'permissions' => json_encode(['raw_data' => true])]);

    app(WorkspaceContext::class)->set($child);
    expect(app(BrandingService::class)->current())->toBeNull();
});

it('a logó-feltöltés throttle-ölt (workspace-enként óránként 10)', function () {
    [$workspace, $user] = AnalyticsFixtures::tenant();
    $png = vmImage('png', 100, 100);

    foreach (range(1, 10) as $i) {
        vmUpload($this, $user, $workspace, $png)->assertSessionHasNoErrors();
    }

    vmUpload($this, $user, $workspace, $png)->assertStatus(429);
});
