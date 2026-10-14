<?php

declare(strict_types=1);

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\CampaignChecklist;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Domains\Models\Domain;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | UI-POLÍROZÁS · 6. pont — A KAMPÁNY TARTALMA HELYBEN.
 |
 | Az alapértelmezett út: „Új template készítése" — a kampány SAJÁT tartalma (a kampányhoz
 | kötött sablon, `templates.owner_campaign_id`), ugyanabban a szerkesztőben. A könyvtárban NEM
 | jelenik meg (ott csak az önálló sablonok vannak), de „Mentés sablonként" önálló példányt ad.
 | A másik ág — „Meglévő template betöltése" — a könyvtár sablonjára hivatkozik (változatlan).
 | ÜRES sablonkönyvtárral is végigvihető egy kampány.
 */

beforeEach(function () {
    $this->withoutVite();

    $this->workspace = Workspace::factory()->create(['name' => 'Nádor Kávéház', 'settings' => ['postal_address' => '1051 Budapest, Nádor u. 1.']]);
    app(WorkspaceContext::class)->set($this->workspace);
    $this->user = StudioFixtures::user($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
    $this->list = ContactList::create(['name' => 'Hírlevél']);
});

function contentCampaign(array $extra = []): Campaign
{
    $campaign = Campaign::create(array_merge([
        'name' => 'Őszi hírlevél',
        'subject' => 'Őszi újdonságok',
        'from_email' => 'hirlevel@pelda.hu',
        'from_name' => 'Pelda',
        'target_lists' => [(string) test()->list->uuid],
        'timezone' => 'Europe/Budapest',
    ], $extra));

    $campaign->forceFill(['send_mode' => 'now'])->save();

    return $campaign;
}

function asContentUser($test)
{
    return $test->actingAs($test->user)->withSession(['workspace_uuid' => $test->workspace->uuid]);
}

it('„Új template készítése": a kampány saját, marketing tartalmat kap lábléccel, és a szerkesztő nyílik', function () {
    $campaign = contentCampaign();

    $response = asContentUser($this)->post("/campaigns/{$campaign->uuid}/content");

    $response->assertRedirect("/campaigns/{$campaign->uuid}/content");

    $template = Template::query()->where('owner_campaign_id', $campaign->getKey())->sole();

    expect($campaign->fresh()->template_id)->toBe($template->getKey())
        ->and($template->marketing)->toBeTrue()
        ->and($template->subject)->toBe('Őszi újdonságok')
        ->and(json_encode($template->blocks))->toContain('"footer"');

    // Ismételt kérés ugyanazt a tartalmat nyitja (nem gyárt újat).
    asContentUser($this)->post("/campaigns/{$campaign->uuid}/content")->assertRedirect();
    expect(Template::query()->where('owner_campaign_id', $campaign->getKey())->count())->toBe(1);
});

it('a tartalom-szerkesztő a sablonszerkesztő, kampány-kontextussal', function () {
    $campaign = contentCampaign();
    asContentUser($this)->post("/campaigns/{$campaign->uuid}/content");

    asContentUser($this)->get("/campaigns/{$campaign->uuid}/content")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Template/Edit')
            ->where('editorMode', true)
            ->where('campaign.uuid', (string) $campaign->uuid)
            ->where('campaign.name', 'Őszi hírlevél')
            ->where('template.marketing', true));
});

it('a kampány saját tartalma NEM jelenik meg a könyvtárban és a „meglévő" választóban', function () {
    $campaign = contentCampaign();
    asContentUser($this)->post("/campaigns/{$campaign->uuid}/content");

    asContentUser($this)->get('/templates')
        ->assertInertia(fn ($page) => $page->has('templates', 0));

    asContentUser($this)->get("/campaigns/{$campaign->uuid}")
        ->assertInertia(fn ($page) => $page
            ->has('templates', 0)
            ->where('campaign.contentMode', 'new')
            ->where('campaign.ownTemplateUuid', (string) $campaign->fresh()->template->uuid));
});

it('„Meglévő template betöltése" módban a kampány a könyvtár sablonjára hivatkozik', function () {
    $library = Template::create(['name' => 'Könyvtári', 'marketing' => true, 'blocks' => [
        ['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'footer', 'attrs' => ['address' => 'Nádor utca 1., 1051 Budapest']],
        ]]]],
    ]]);
    $campaign = contentCampaign(['template_id' => $library->getKey()]);

    asContentUser($this)->get("/campaigns/{$campaign->uuid}")
        ->assertInertia(fn ($page) => $page
            ->has('templates', 1)
            ->where('campaign.contentMode', 'existing')
            ->where('campaign.ownTemplateUuid', null));
});

it('más kampány saját tartalmára nem köthető kampány (és idegen kampány tartalma 404)', function () {
    $first = contentCampaign();
    asContentUser($this)->post("/campaigns/{$first->uuid}/content");
    $owned = $first->fresh()->template;

    $second = contentCampaign(['name' => 'Másik']);

    asContentUser($this)->put("/campaigns/{$second->uuid}", ['name' => 'Másik', 'templateUuid' => (string) $owned->uuid])
        ->assertSessionHasErrors('templateUuid');
    expect($second->fresh()->template_id)->toBeNull();

    // Idegen workspace kampánya: a global scope miatt 404.
    $other = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($other);
    $foreign = Campaign::create(['name' => 'Idegen', 'subject' => '']);
    app(WorkspaceContext::class)->set($this->workspace);

    asContentUser($this)->post("/campaigns/{$foreign->uuid}/content")->assertNotFound();
    asContentUser($this)->get("/campaigns/{$foreign->uuid}/content")->assertNotFound();
});

it('D308: új tartalom / újrakötés CSAK vázlatnál — ütemezett (szerző és publikáló) és küldés alatti kampánynál elutasít, a sablon nem cserélődik', function (string $status, string $role) {
    $library = Template::create(['name' => 'Jóváhagyott', 'marketing' => true, 'blocks' => [
        ['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'footer', 'attrs' => ['address' => 'Nádor utca 1., 1051 Budapest']],
        ]]]],
    ]]);
    $campaign = contentCampaign(['template_id' => $library->getKey()]);
    $campaign->forceFill(['status' => $status])->saveQuietly();

    $this->user = StudioFixtures::user($this->workspace, $role);

    asContentUser($this)->post("/campaigns/{$campaign->uuid}/content")->assertSessionHas('error');

    expect($campaign->fresh()->template_id)->toBe($library->getKey())
        ->and(Template::query()->where('owner_campaign_id', $campaign->getKey())->count())->toBe(0);
})->with([
    'ütemezett, szerző' => ['scheduled', 'author'],
    'ütemezett, publikáló' => ['scheduled', 'owner'],
    'küldés alatt' => ['sending', 'owner'],
]);

it('D308: vázlat kampánynál a szerző is létrehozhatja a saját tartalmat', function () {
    $this->user = StudioFixtures::user($this->workspace, 'author');
    $campaign = contentCampaign();

    asContentUser($this)->post("/campaigns/{$campaign->uuid}/content")->assertRedirect("/campaigns/{$campaign->uuid}/content");
    expect($campaign->fresh()->template?->owner_campaign_id)->toBe($campaign->getKey());
});

it('D310: A/B változatként sem köthető más kampány saját tartalma (422)', function () {
    $first = contentCampaign();
    asContentUser($this)->post("/campaigns/{$first->uuid}/content");
    $owned = $first->fresh()->template;

    $second = contentCampaign(['name' => 'Másik']);

    asContentUser($this)->post("/campaigns/{$second->uuid}/variants", ['label' => 'B', 'templateUuid' => (string) $owned->uuid, 'weight' => 50])
        ->assertSessionHasErrors('templateUuid');
    expect($second->variants()->count())->toBe(0);
});

it('„Mentés sablonként": a saját tartalom önálló könyvtári sablonként is elmenthető', function () {
    $campaign = contentCampaign();
    asContentUser($this)->post("/campaigns/{$campaign->uuid}/content");
    $owned = $campaign->fresh()->template;

    asContentUser($this)->postJson("/templates/{$owned->uuid}/copy", [
        'name' => 'Őszi sablon',
        'subject' => (string) $owned->subject,
        'preheader' => null,
        'blocks' => $owned->blocks,
        'styles' => $owned->styles,
    ])->assertSuccessful();

    $copy = Template::query()->where('name', 'Őszi sablon')->sole();

    expect($copy->owner_campaign_id)->toBeNull();
    asContentUser($this)->get('/templates')->assertInertia(fn ($page) => $page->has('templates', 1)->where('templates.0.name', 'Őszi sablon'));
});

it('ÜRES sablonkönyvtárral is KÉSZ a kampány: a saját tartalom (valódi címmel) zöld', function () {
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request('ok@pelda.hu'));
    Contact::create(['email' => 'ok@pelda.hu', 'status' => 'subscribed'])->lists()->attach($this->list, ['status' => 'subscribed', 'subscribed_at' => now()]);

    expect(Template::query()->count())->toBe(0);

    $campaign = contentCampaign();
    asContentUser($this)->post("/campaigns/{$campaign->uuid}/content");

    $checklist = app(CampaignChecklist::class)->evaluate($campaign->fresh());

    expect($checklist['ready'])->toBeTrue()
        ->and(collect($checklist['sections'])->firstWhere('key', 'content')['template']['owned'])->toBeTrue();
});
