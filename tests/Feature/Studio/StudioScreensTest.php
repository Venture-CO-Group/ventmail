<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Consent\Models\Consent;
use App\Modules\Identity\Enums\Role;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | M4-3 · A STUDIO FELÜLETE (a Mailchimp-oldal): közönség, sablonok + blokk-szerkesztő,
 | kampányok.
 |
 | A közös szabály: a felület nem tud többet, mint a motor. Amit a `BlockTreeValidator`
 | vagy a `CampaignLifecycle` tilt, azt a képernyő sem engedi meg — és a hibát OTT mutatja,
 | ahol keletkezett, nem a küldésnél.
 */

function studioUser(Workspace $workspace, string $role = 'owner'): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'studio-'.uniqid(),
        'name' => 'Marketinges',
        'email' => 'studio-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);

    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

    return $user;
}

it('a közönség-képernyő a CONSENT-ÁLLAPOTOT a kapcsolat mellett mutatja', function () {
    /*
     | A marketing küldés jogalapja ez. Ha a consent másik képernyőn lakna, a marketinges
     | kiküldene egy kampányt, és utána keresné, miért esett ki a fele a 3. kapuban.
     */
    $workspace = Workspace::factory()->create();
    $user = studioUser($workspace);

    app(WorkspaceContext::class)->runFor($workspace, function () {
        Contact::create(['email' => 'megerositett@pelda.hu', 'status' => 'subscribed']);
        Contact::create(['email' => 'nincs@pelda.hu', 'status' => 'subscribed']);

        Consent::create([
            'email' => 'megerositett@pelda.hu',
            'channel' => 'marketing',
            'status' => 'confirmed',
            'source' => 'form',
            'confirmed_at' => now(),
        ]);
    });

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/audience')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Audience/Index')
            ->has('contacts.items', 2)
            ->where('contacts.items.1.email', 'megerositett@pelda.hu')
            ->where('contacts.items.1.consent', 'confirmed')
            ->where('contacts.items.0.consent', 'none')
        );
});

it('a blokk-szerkesztő a MENTÉSNÉL utasítja el a hibás fát, nem a küldésnél', function () {
    /*
     | A `BlockTreeValidator` ugyanaz, amit a küldő út használ. A hibát ott mutatjuk meg,
     | ahol keletkezett — a „majd a küldésnél kiderül" azt jelentené, hogy a marketinges a
     | kampány indításakor szembesül vele.
     */
    $workspace = Workspace::factory()->create();
    $user = studioUser($workspace);

    // A MARKETING sablon nem lehet üres: a modell megköveteli a leiratkozó blokkot (61.
    // kritérium), tehát a fixtúra is érvényes fával indul.
    $template = app(WorkspaceContext::class)->runFor($workspace, fn () => Template::create([
        'name' => 'Hírlevél',
        'subject' => 'Újdonságok',
        'marketing' => true,
        'blocks' => [[
            'type' => 'section',
            'children' => [['type' => 'column', 'children' => [['type' => 'unsubscribe']]]],
        ]],
    ]));

    // A GYÖKÉRBEN csak `section` állhat — egy `text` ott értelmezhetetlen.
    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/templates/'.$template->uuid)
        ->put('/templates/'.$template->uuid, [
            'name' => 'Hírlevél',
            'subject' => 'Újdonságok',
            'blocks' => [['type' => 'text', 'attrs' => ['content' => 'Szia']]],
        ])
        ->assertSessionHasErrors('blocks');

    // A FA VÁLTOZATLAN: a hibás mentés nem írt felül semmit.
    expect($template->fresh()->blocks)->toHaveCount(1);
});

it('a MARKETING sablon leiratkozó blokk nélkül nem menthető', function () {
    // 61. kritérium: a szabály csak akkor kikényszeríthető, ha a blokk FELISMERHETŐ —
    // ezért van külön `unsubscribe` típus, nem „egy text blokk linkkel".
    $workspace = Workspace::factory()->create();
    $user = studioUser($workspace);

    // A MARKETING sablon nem lehet üres: a modell megköveteli a leiratkozó blokkot (61.
    // kritérium), tehát a fixtúra is érvényes fával indul.
    $template = app(WorkspaceContext::class)->runFor($workspace, fn () => Template::create([
        'name' => 'Hírlevél',
        'subject' => 'Újdonságok',
        'marketing' => true,
        'blocks' => [[
            'type' => 'section',
            'children' => [['type' => 'column', 'children' => [['type' => 'unsubscribe']]]],
        ]],
    ]));

    $withoutUnsubscribe = [[
        'type' => 'section',
        'children' => [[
            'type' => 'column',
            'children' => [['type' => 'text', 'attrs' => ['content' => 'Szia']]],
        ]],
    ]];

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/templates/'.$template->uuid)
        ->put('/templates/'.$template->uuid, [
            'name' => 'Hírlevél',
            'subject' => 'Újdonságok',
            'blocks' => $withoutUnsubscribe,
        ])
        ->assertSessionHasErrors('blocks');

    // …leiratkozó blokkal viszont megy, és az ELŐNÉZET a valódi fordítón készül.
    $withUnsubscribe = [[
        'type' => 'section',
        'children' => [[
            'type' => 'column',
            'children' => [
                ['type' => 'text', 'attrs' => ['content' => 'Szia']],
                ['type' => 'unsubscribe'],
            ],
        ]],
    ]];

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/templates/'.$template->uuid)
        ->put('/templates/'.$template->uuid, [
            'name' => 'Hírlevél',
            'subject' => 'Újdonságok',
            'blocks' => $withUnsubscribe,
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/templates/'.$template->uuid)
        ->assertInertia(fn ($page) => $page
            ->component('Template/Edit')
            ->has('template.blocks', 1)
            // AZ ELŐNÉZET a valódi MJML-fordítón megy át: amit itt lát, az megy ki.
            ->where('preview.error', null)
            ->has('preview.html')
        );
});

it('a kampány INDÍTÁSA külön képesség — az author írhat, de nem küldhet', function () {
    /*
     | A `campaign.create` és a `campaign.publish` szándékosan két külön képesség: a
     | tartalmi szerep megírhatja a kampányt, a kiküldés viszont felelősség.
     */
    $workspace = Workspace::factory()->create();
    $author = studioUser($workspace, Role::Author->value);

    $campaign = app(WorkspaceContext::class)->runFor($workspace, fn () => Campaign::create([
        'name' => 'Teszt',
        'subject' => 'Tárgy',
    ]));

    $this->actingAs($author)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/campaigns')
        ->assertOk();

    $this->actingAs($author)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/campaigns/'.$campaign->uuid)
        ->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'start'])
        ->assertForbidden();
});

it('a kampány célzása a BECSÜLT közönséggel együtt jelenik meg', function () {
    // A „kinek megy ki?" kérdésre az indítás ELŐTT kell válaszolni — és a becslés a
    // VALÓDI feloldón megy át, ugyanazon, amelyik a küldéskor is dolgozik.
    $workspace = Workspace::factory()->create();
    $user = studioUser($workspace);

    [$campaign, $list] = app(WorkspaceContext::class)->runFor($workspace, function () {
        $list = ContactList::create(['name' => 'Hírlevél']);

        $contact = Contact::create(['email' => 'cimzett@pelda.hu', 'status' => 'subscribed']);
        $list->contacts()->attach($contact->getKey());

        $campaign = Campaign::create([
            'name' => 'Szeptemberi',
            'subject' => 'Újdonságok',
            'target_lists' => [$list->uuid],
        ]);

        return [$campaign, $list];
    });

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/campaigns/'.$campaign->uuid)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Campaign/Edit')
            ->where('campaign.targetLists', [$list->uuid])
            ->where('estimate', 1)
            ->where('campaign.editable', true)
        );
});

it('a KÜLDÉS ALATT álló kampány célzása nem írható át', function () {
    /*
     | A modell `saving` hookja tiltja — a felület nem trükközi ki, hanem megmutatja az
     | okot. Fél kampányt máshova küldeni rosszabb, mint nem tudni szerkeszteni.
     */
    $workspace = Workspace::factory()->create();
    $user = studioUser($workspace);

    $campaign = app(WorkspaceContext::class)->runFor($workspace, function () {
        $campaign = Campaign::create(['name' => 'Fut', 'subject' => 'Tárgy']);
        $campaign->forceFill(['status' => 'sending'])->saveQuietly();

        return $campaign;
    });

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/campaigns/'.$campaign->uuid)
        ->put('/campaigns/'.$campaign->uuid, [
            'name' => 'Átírva',
            'subject' => 'Másik tárgy',
            'targetLists' => [],
        ])
        ->assertSessionHas('error');

    expect($campaign->fresh()->name)->toBe('Fut');
});

it('a Studio képernyői MÁS tenant adatát nem mutatják', function () {
    $enyem = Workspace::factory()->create();
    $idegen = Workspace::factory()->create();
    $user = studioUser($enyem);

    app(WorkspaceContext::class)->runFor($idegen, function () {
        ContactList::create(['name' => 'Idegen lista']);
        Contact::create(['email' => 'idegen@pelda.hu', 'status' => 'subscribed']);
        Template::create(['name' => 'Idegen sablon', 'subject' => 'X', 'marketing' => false, 'blocks' => []]);
        Campaign::create(['name' => 'Idegen kampány', 'subject' => 'X']);
    });

    $session = ['workspace_uuid' => $enyem->uuid];

    $this->actingAs($user)->withSession($session)->get('/audience')
        ->assertInertia(fn ($page) => $page->has('lists', 0)->has('contacts.items', 0));

    $this->actingAs($user)->withSession($session)->get('/templates')
        ->assertInertia(fn ($page) => $page->has('templates', 0));

    $this->actingAs($user)->withSession($session)->get('/campaigns')
        ->assertInertia(fn ($page) => $page->has('campaigns', 0));
});
