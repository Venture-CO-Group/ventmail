<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Enums\Role;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\StoredTemplates\Services\TemplateRenderer;
use App\Modules\Template\Models\SavedBlock;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Models\TemplateAsset;
use App\Modules\Template\Models\TemplateVersion;
use App\Modules\Template\Services\MergeVariables;
use App\Modules\Template\Services\MjmlCompiler;
use App\Modules\Template\Services\MjmlWriter;
use App\Modules\Template\Services\TemplateTestSender;
use App\Modules\Template\Services\TemplateVersions;
use App\Modules\Template\Services\WebVersionTokens;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 | v1.1 · 4. modul — a szerkesztő és a sablonkönyvtár HTTP-útjai: indulósablon, autosave,
 | verziók + visszaállítás, előnézet, optimalizálás, másolat, teszt-küldés a kapun át,
 | képfeltöltés, mentett blokkok, webes nézet, tenant-izoláció.
 */

beforeEach(function () {
    $this->withoutVite();

    // A spam-becslés domain-feketelistája DNS-t kérdezne — a teszt determinisztikus.
    config(['deliverability.spam_score.domain_blocklist_zone' => '']);

    $this->workspace = Workspace::factory()->create(['name' => 'Nádor Kávéház']);
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->user = User::factory()->create(['name' => 'Szabó Bence', 'email' => 'bence@pelda.hu']);
    $this->workspace->members()->create(['user_id' => $this->user->id, 'roles' => [Role::Owner->value]]);

    $this->actingAs($this->user)->withSession(['workspace_uuid' => $this->workspace->uuid]);

    FakeDriver::reset();
});

function editorTemplate(array $overrides = []): Template
{
    return Template::create(array_merge([
        'name' => 'Őszi hírlevél '.uniqid(),
        'subject' => 'Ősz',
        'marketing' => true,
        'blocks' => [
            ['type' => 'section', 'children' => [['type' => 'column', 'children' => [
                ['type' => 'heading', 'attrs' => ['content' => 'Szia, {{ contact.first_name || Kedves vendégünk }}!']],
            ]]]],
            ['type' => 'section', 'children' => [['type' => 'column', 'children' => [
                ['type' => 'footer', 'attrs' => ['address' => '1000 Budapest, Minta u. 1.']],
            ]]]],
        ],
    ], $overrides));
}

it('a könyvtár a saját sablonokat, a mentett blokkokat és a hat indulósablont adja', function () {
    editorTemplate(['name' => 'Saját']);
    SavedBlock::query()->create(['name' => 'Fejléc', 'kind' => 'section', 'block' => ['type' => 'section', 'children' => []]]);

    $this->get('/templates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Template/Index')
            ->has('templates', 1)
            ->has('templates.0.thumb')
            ->has('savedBlocks', 1)
            ->has('starters', 6)
            ->where('starters.0.key', 'newsletter'));
});

it('indulósablonból létrehozva a felhasználó nyelvén áll össze, és az első verzió megszületik', function () {
    app()->setLocale('en');
    $this->user->forceFill(['locale' => 'en'])->save();

    $response = $this->post('/templates', ['name' => 'Autumn promo', 'starter' => 'promotion']);

    $template = Template::query()->where('name', 'Autumn promo')->firstOrFail();

    $response->assertRedirect('/templates/'.$template->uuid);

    expect($template->starter_key)->toBe('promotion')
        ->and($template->styles)->toBeArray()
        ->and($template->html)->toContain('Shop now')
        ->and(TemplateVersion::query()->where('template_id', $template->id)->count())->toBe(1);
});

it('az üres marketing sablon a kötelező lábléccel indul', function () {
    $this->post('/templates', ['name' => 'Üres', 'subject' => 'S', 'marketing' => true])->assertRedirect();

    $template = Template::query()->where('name', 'Üres')->firstOrFail();

    expect(json_encode($template->blocks))->toContain('"footer"');
});

it('UI-polírozás 7: az üres TRANZAKCIÓS sablon is láblécet kap — cégnév + postai cím, leiratkozó nélkül', function () {
    $this->workspace->forceFill(['settings' => ['postal_address' => '1051 Budapest, Nádor u. 1.']])->save();

    $this->post('/templates', ['name' => 'Számla', 'subject' => 'S', 'marketing' => false])->assertRedirect();

    $template = Template::query()->where('name', 'Számla')->firstOrFail();
    $blocks = (array) $template->blocks;
    $last = json_encode(end($blocks), JSON_UNESCAPED_UNICODE);

    expect($blocks)->toHaveCount(2)
        ->and($last)->toContain('Nádor Kávéház')
        ->and($last)->toContain('1051 Budapest, Nádor u. 1.')
        ->and($last)->not->toContain('"footer"')
        ->and((string) $template->html)->not->toContain('unsubscribe_url');
});

it('UI-polírozás 7: minden új sablon (üres, indulósablon, mindkét csatorna) láblécre végződik', function () {
    foreach ([['marketing' => true], ['marketing' => false], ['starter' => 'thanks']] as $index => $input) {
        $this->post('/templates', ['name' => "Új {$index}", ...$input])->assertRedirect();

        $blocks = (array) Template::query()->where('name', "Új {$index}")->firstOrFail()->blocks;

        expect(json_encode(end($blocks), JSON_UNESCAPED_UNICODE))->toContain('Nádor Kávéház');
    }
});

it('a szerkesztő oldal a katalógust, a megengedett teszt-címzetteket és a kompakt héjat kapja', function () {
    $template = editorTemplate();

    $this->get('/templates/'.$template->uuid)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Template/Edit')
            ->where('editorMode', true)
            ->has('catalog.blocks', 14)
            ->has('catalog.layouts', 6)
            ->has('catalog.patterns', 3)
            ->where('catalog.mergeTags.0.key', 'contact.first_name')
            ->where('testRecipients.0.email', 'bence@pelda.hu')
            ->where('senderDomains.0', 'pelda.hu'));
});

it('az AUTOSAVE ment, és verziót csak időközönként ír — a kézi mentés mindig', function () {
    $template = editorTemplate();
    app(TemplateVersions::class)->snapshot($template, 'create');

    $blocks = $template->blocks;
    $blocks[0]['children'][0]['children'][] = ['type' => 'text', 'attrs' => ['content' => 'Új bekezdés']];

    $this->putJson("/templates/{$template->uuid}/autosave", ['blocks' => $blocks, 'name' => $template->name])
        ->assertOk()
        ->assertJsonStructure(['savedAt', 'version', 'html']);

    expect($template->fresh()->html)->toContain('Új bekezdés')
        // Az utolsó verzió friss (< 10 perc): az autosave NEM írt újat.
        ->and(TemplateVersion::query()->where('template_id', $template->id)->count())->toBe(1);

    $blocks[0]['children'][0]['children'][] = ['type' => 'text', 'attrs' => ['content' => 'Még egy']];

    $this->putJson("/templates/{$template->uuid}/autosave", ['blocks' => $blocks, 'manual' => true])->assertOk();

    expect(TemplateVersion::query()->where('template_id', $template->id)->count())->toBe(2);
});

it('a marketing sablon a KÖTELEZŐ lábléc nélkül nem menthető — a fa változatlan marad', function () {
    $template = editorTemplate();

    $this->putJson("/templates/{$template->uuid}/autosave", ['blocks' => [
        ['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'text', 'attrs' => ['content' => 'Lábléc nélkül']],
            ['type' => 'unsubscribe'],
        ]]]],
    ]])->assertStatus(422)->assertJsonValidationErrors('blocks');

    expect($template->fresh()->html)->not->toContain('Lábléc nélkül');
});

it('a VISSZAÁLLÍTÁS új verziót ír, a régiek megmaradnak', function () {
    $template = editorTemplate();
    $versions = app(TemplateVersions::class);
    $versions->snapshot($template, 'create');

    $blocks = $template->blocks;
    $blocks[0]['children'][0]['children'][0]['attrs']['content'] = 'Második változat';
    $this->putJson("/templates/{$template->uuid}/autosave", ['blocks' => $blocks, 'manual' => true])->assertOk();

    $this->getJson("/templates/{$template->uuid}/versions")
        ->assertOk()
        ->assertJsonCount(2, 'versions')
        ->assertJsonPath('versions.0.number', 2);

    $this->postJson("/templates/{$template->uuid}/versions/1/restore")
        ->assertOk()
        ->assertJsonPath('version', 3);

    expect($template->fresh()->html)->not->toContain('Második változat')
        ->and(TemplateVersion::query()->where('template_id', $template->id)->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'template.version_restored')->exists())->toBeTrue();
});

it('a verzió pillanatképe megváltoztathatatlan', function () {
    $template = editorTemplate();
    $version = app(TemplateVersions::class)->snapshot($template, 'create');

    expect(fn () => $version->forceFill(['name' => 'átírva'])->save())->toThrow(LogicException::class);
});

it('az ELŐNÉZET a mentetlen állapotból a valódi fordítón készül', function () {
    $template = editorTemplate();

    $this->postJson("/templates/{$template->uuid}/preview", [
        'blocks' => [['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'text', 'attrs' => ['content' => 'Mentetlen szöveg']],
        ]]]]],
        'styles' => ['width' => 640],
    ])
        ->assertOk()
        ->assertJsonPath('bytes', fn ($bytes) => $bytes > 0)
        ->assertJson(fn ($json) => $json->where('html', fn ($html) => str_contains($html, 'Mentetlen szöveg') && str_contains($html, 'width="640"'))->etc());

    // Az előnézet NEM ment.
    expect($template->fresh()->html)->not->toContain('Mentetlen szöveg');
});

it('az OPTIMALIZÁLÁS: linkek, képek, súly, spam-becslés, kötelező elemek', function () {
    $template = editorTemplate();

    $report = $this->postJson("/templates/{$template->uuid}/optimize", [
        'subject' => 'INGYENES AJÁNLAT!!!',
        'blocks' => [['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'button', 'attrs' => ['content' => 'Rossz', 'href' => 'https://example.com']],
            ['type' => 'button', 'attrs' => ['content' => 'Jó', 'href' => 'https://nadorkavehaz.hu/x?utm_source=a&utm_medium=email&utm_campaign=osz']],
            ['type' => 'text', 'attrs' => ['content' => '<a href="https://bit.ly/x">rövid</a>']],
            ['type' => 'image', 'attrs' => ['src' => 'https://pelda.hu/k.png']],
        ]]]]],
    ])->assertOk()->json();

    expect($report['links']['total'])->toBe(3)
        ->and($report['links']['broken'])->toBe(1)
        ->and($report['links']['missingUtm'])->toBe(2)
        ->and($report['images']['missingAlt'])->toBe(1)
        ->and($report['weight']['limit'])->toBe(102 * 1024)
        ->and($report['weight']['clipped'])->toBeFalse()
        ->and(collect($report['spam']['findings'])->pluck('rule')->all())->toContain('SHOUTING_SUBJECT', 'URL_SHORTENER')
        ->and($report['compliance']['missing'])->toBe(['postal_address', 'unsubscribe', 'preferences']);
});

it('a MÁSOLAT („mentés másként / sablonként") új sablon, saját első verzióval', function () {
    $template = editorTemplate();

    $response = $this->postJson("/templates/{$template->uuid}/copy", ['name' => 'Másolat'])->assertCreated();

    $copy = Template::query()->where('uuid', $response->json('uuid'))->firstOrFail();

    expect($copy->name)->toBe('Másolat')
        ->and($copy->versions()->count())->toBe(1);

    $this->postJson("/templates/{$template->uuid}/copy", ['name' => 'Másolat'])->assertStatus(422);
});

it('a TESZT-KÜLDÉS a PreSendGate-en megy, a fake driverrel, csak megengedett címzettnek', function () {
    $template = editorTemplate();

    $this->postJson("/templates/{$template->uuid}/test-send", [
        'recipients' => ['bence@pelda.hu'],
        'from_email' => 'hello@pelda.hu',
    ], ['Idempotency-Key' => 'teszt-1'])
        ->assertStatus(202)
        ->assertJsonPath('allowed', true);

    $message = Message::query()->where('idempotency_key', TemplateTestSender::key((string) $template->uuid, 'teszt-1'))->firstOrFail();

    // D52: a MARKETING sablon tesztje a campaign csatornán (mk pool) megy.
    expect($message->channel)->toBe('campaign')
        ->and($message->subject)->toMatch('/^\[(TESZT|TEST)\] Ősz$/u')
        ->and($message->tags)->toContain('template-test')
        ->and(FakeDriver::$sent)->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'template.test_sent')->exists())->toBeTrue();

    // S10 · a merge-tag a küldő MINTA-adatával helyettesítődik — a driver által kapott törzsben.
    expect((string) FakeDriver::$sent[0]['bodies']['bence@pelda.hu']['html'])->toContain('Szia, Szabó!');

    // UGYANAZ a kulcs: nincs második levél.
    $this->postJson("/templates/{$template->uuid}/test-send", [
        'recipients' => ['bence@pelda.hu'],
        'from_email' => 'hello@pelda.hu',
    ], ['Idempotency-Key' => 'teszt-1'])->assertStatus(202);

    expect(FakeDriver::$sent)->toHaveCount(1);
});

it('a teszt-küldés idegen címre, hitelesítetlen feladóval és kulcs nélkül elutasít', function () {
    $template = editorTemplate();

    $this->postJson("/templates/{$template->uuid}/test-send", ['recipients' => ['idegen@kulso.hu'], 'from_email' => 'hello@pelda.hu'], ['Idempotency-Key' => 'k1'])
        ->assertStatus(422)->assertJsonValidationErrors('recipients');

    $this->postJson("/templates/{$template->uuid}/test-send", ['recipients' => ['bence@pelda.hu'], 'from_email' => 'hello@nemhitelesitett.hu'], ['Idempotency-Key' => 'k2'])
        ->assertStatus(422)->assertJsonValidationErrors('from_email');

    $this->postJson("/templates/{$template->uuid}/test-send", ['recipients' => ['bence@pelda.hu'], 'from_email' => 'hello@pelda.hu'])
        ->assertStatus(400)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');

    expect(FakeDriver::$sent)->toHaveCount(0);
});

it('a teszt-küldést a küldési mód (seed-mód) ugyanúgy megállítja, mint bármely levelet', function () {
    config(['sending.live' => false, 'sending.allowlist' => []]);

    $template = editorTemplate();

    $this->postJson("/templates/{$template->uuid}/test-send", ['recipients' => ['bence@pelda.hu'], 'from_email' => 'hello@pelda.hu'], ['Idempotency-Key' => 'seed-1'])
        ->assertStatus(422)
        ->assertJsonPath('allowed', false)
        ->assertJsonPath('reason', 'SENDING_RESTRICTED');

    expect(FakeDriver::$sent)->toHaveCount(0);
});

it('KÉPFELTÖLTÉS: tartalom szerinti MIME, méret- és képméret-korlát, tenant-útvonal', function () {
    Storage::fake('public');

    // 1×1-es PNG — GD nélkül, bájtokból.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    $response = $this->post('/template-assets', ['file' => UploadedFile::fake()->createWithContent('logo.png', $png)], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('width', 1);

    $asset = TemplateAsset::query()->where('uuid', $response->json('uuid'))->firstOrFail();

    expect($asset->path)->toStartWith('templates/'.strtolower((string) $this->workspace->uuid).'/')
        ->and($asset->url)->toStartWith('https://')
        ->and($asset->original_name)->toBe('logo.png');

    Storage::disk('public')->assertExists($asset->path);

    // SVG (szkriptet hordozhat) és a kiterjesztéssel álcázott szöveg: elutasítva.
    $this->post('/template-assets', ['file' => UploadedFile::fake()->createWithContent('x.svg', '<svg onload="alert(1)"/>')], ['Accept' => 'application/json'])
        ->assertStatus(422);
    $this->post('/template-assets', ['file' => UploadedFile::fake()->createWithContent('x.png', 'nem kép')], ['Accept' => 'application/json'])
        ->assertStatus(422);

    // Óriási képméret (dekompressziós bomba): az IHDR 5000 px szélességet állít.
    $bomb = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 5000, 10)."\x08\x06\x00\x00\x00".pack('N', 0);
    $this->post('/template-assets', ['file' => UploadedFile::fake()->createWithContent('bomb.png', $bomb)], ['Accept' => 'application/json'])
        ->assertStatus(422);

    config(['template.assets.max_bytes' => 10]);
    $this->post('/template-assets', ['file' => UploadedFile::fake()->createWithContent('big.png', $png)], ['Accept' => 'application/json'])
        ->assertStatus(422);
});

it('a MENTETT BLOKK ugyanazon a validátoron megy át, és törölhető', function () {
    $this->postJson('/template-blocks', ['name' => 'Rossz', 'kind' => 'block', 'block' => ['type' => 'section']])
        ->assertStatus(422);

    $uuid = $this->postJson('/template-blocks', [
        'name' => 'Gomb',
        'kind' => 'block',
        'block' => ['type' => 'button', 'attrs' => ['content' => 'Foglalj', 'href' => 'https://pelda.hu']],
    ])->assertCreated()->json('uuid');

    $this->deleteJson('/template-blocks/'.$uuid)->assertOk();

    expect(SavedBlock::query()->count())->toBe(0);
});

it('TENANT-IZOLÁCIÓ: idegen workspace sablonja, verziója, blokkja és képe nem érhető el', function () {
    $other = Workspace::factory()->create();

    [$foreign, $foreignBlock, $foreignAsset] = app(WorkspaceContext::class)->runFor($other, function () {
        $template = Template::create(['name' => 'Idegen', 'marketing' => false, 'blocks' => []]);
        app(TemplateVersions::class)->snapshot($template, 'create');

        return [
            $template,
            SavedBlock::query()->create(['name' => 'Idegen', 'kind' => 'section', 'block' => ['type' => 'section']]),
            TemplateAsset::query()->create(['driver' => 'local', 'path' => 'templates/x/y.png', 'url' => 'https://x/y.png', 'mime' => 'image/png', 'size' => 1, 'width' => 1, 'height' => 1]),
        ];
    });

    $this->get('/templates/'.$foreign->uuid)->assertNotFound();
    $this->putJson("/templates/{$foreign->uuid}/autosave", ['blocks' => []])->assertNotFound();
    $this->getJson("/templates/{$foreign->uuid}/versions")->assertNotFound();
    $this->postJson("/templates/{$foreign->uuid}/versions/1/restore")->assertNotFound();
    $this->postJson("/templates/{$foreign->uuid}/copy", ['name' => 'Lopott'])->assertNotFound();
    $this->postJson("/templates/{$foreign->uuid}/test-send", ['recipients' => ['bence@pelda.hu'], 'from_email' => 'a@pelda.hu'], ['Idempotency-Key' => 'x'])->assertNotFound();
    $this->post('/templates', ['name' => 'Lopott', 'from_template' => $foreign->uuid])->assertNotFound();
    $this->deleteJson('/template-blocks/'.$foreignBlock->uuid)->assertNotFound();
    $this->deleteJson('/template-assets/'.$foreignAsset->uuid)->assertNotFound();

    $this->get('/templates')->assertInertia(fn ($page) => $page->has('templates', 0)->has('savedBlocks', 0));
});

it('a sablon törlése soft delete és auditált', function () {
    $template = editorTemplate();

    $this->delete('/templates/'.$template->uuid)->assertRedirect('/templates');

    expect(Template::withTrashed()->find($template->id)->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'deleted')->where('auditable_type', Template::class)->exists())->toBeTrue();
});

it('a WEBES NÉZET a kanonikus törzset mutatja, CSP-vel, hamis tokenre 404', function () {
    $template = editorTemplate();

    $this->postJson("/templates/{$template->uuid}/test-send", ['recipients' => ['bence@pelda.hu'], 'from_email' => 'hello@pelda.hu'], ['Idempotency-Key' => 'web-1'])
        ->assertStatus(202);

    $message = Message::query()->where('idempotency_key', TemplateTestSender::key((string) $template->uuid, 'web-1'))->firstOrFail();

    // A `browser_url` fenntartott változó: címzettenként ugyanaz a webes nézet.
    expect($message->bodyLinkVariables('bence@pelda.hu'))->toHaveKey('browser_url');

    $token = app(WebVersionTokens::class)->issue((int) $this->workspace->id, (string) $message->uuid);

    auth()->logout();

    $this->get('/w/'.$token)
        ->assertOk()
        ->assertHeader('Content-Security-Policy')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('Szia, Kedves vendégünk!', false);

    $this->get('/w/hamis-token')->assertNotFound();
});

it('a KAMPÁNY-küldés a kontakt merge-változóit és a hivatkozott szegmens-tagságot adja', function () {
    $segment = Segment::query()->create(['name' => 'VIP', 'definition' => ['match' => 'all', 'rules' => [
        ['field' => 'email', 'op' => 'contains', 'value' => 'vip'],
    ]]]);

    $vip = Contact::create(['email' => 'vip@pelda.hu', 'first_name' => 'Anna', 'status' => 'subscribed', 'attributes' => ['company' => 'Kávé Kft.']]);
    $plain = Contact::create(['email' => 'sima@pelda.hu', 'first_name' => 'Béla', 'status' => 'subscribed']);

    $html = app(MjmlCompiler::class)->compile(app(MjmlWriter::class)->write([
        ['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'text', 'attrs' => ['content' => 'Szia {{ contact.first_name }} ({{ contact.company || – }})']],
            ['type' => 'text', 'attrs' => ['content' => 'VIP kupon', 'condition' => ['kind' => 'segment', 'segment' => (string) $segment->uuid, 'op' => 'in']]],
        ]]]],
    ]));

    $variables = app(MergeVariables::class);
    $variables->prepare([$html], [$vip->id, $plain->id]);

    $renderer = app(TemplateRenderer::class);

    expect($renderer->render($html, $variables->forContact($vip, $this->workspace), conditions: true))
        ->toContain('Szia Anna (Kávé Kft.)')->toContain('VIP kupon');
    expect($renderer->render($html, $variables->forContact($plain, $this->workspace), conditions: true))
        ->toContain('Szia Béla (–)')->not->toContain('VIP kupon');
});
