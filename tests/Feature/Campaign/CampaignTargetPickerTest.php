<?php

declare(strict_types=1);

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Segment;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · D284 — a kampány CÍMZETT-választója: a listák és szegmensek SZERVEROLDALON keresve és
 | lapozva jönnek (`/campaigns/targets/{kind}`), a kiválasztottak neve pedig a kampánnyal együtt
 | (`selectedTargets`) — így a kiválasztott elem akkor is látszik, ha nincs az aktuális lapon.
 */

function pickerTenant(string $role = 'owner'): array
{
    $workspace = Workspace::factory()->create();
    $user = StudioFixtures::user($workspace, $role);
    app(WorkspaceContext::class)->set($workspace);

    return [$workspace, $user];
}

it('a lista-választó keres és lapoz, a feliratkozott létszámmal', function () {
    [$workspace, $user] = pickerTenant();

    foreach (range(1, 12) as $i) {
        ContactList::create(['name' => "Hírlevél {$i}"]);
    }
    $vip = ContactList::create(['name' => 'VIP vendégek']);
    $contact = Contact::create(['email' => 'v@pelda.hu', 'status' => 'subscribed']);
    $vip->contacts()->attach($contact->getKey(), ['status' => 'subscribed']);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->getJson('/campaigns/targets/lists?'.http_build_query(['targets_search' => 'vip']))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.uuid', (string) $vip->uuid)
        ->assertJsonPath('items.0.contacts', 1);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->getJson('/campaigns/targets/lists?targets_page=2')
        ->assertOk()
        ->assertJsonPath('page', 2)
        ->assertJsonPath('last', 2)
        ->assertJsonCount(3, 'items');
});

it('a szegmens-választó a becsült méretet adja', function () {
    [$workspace, $user] = pickerTenant();
    Segment::create(['name' => 'Aktívak', 'definition' => ['match' => 'all', 'conditions' => []], 'estimated_size' => 42]);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->getJson('/campaigns/targets/segments')
        ->assertOk()
        ->assertJsonPath('items.0.name', 'Aktívak')
        ->assertJsonPath('items.0.contacts', 42);
});

it('ismeretlen fajta 404, kampányjog nélkül 403', function () {
    [$workspace, $analyst] = pickerTenant('analyst');

    $this->actingAs($analyst)->withSession(['workspace_uuid' => $workspace->uuid])
        ->getJson('/campaigns/targets/lists')
        ->assertForbidden();

    [$workspace2, $owner] = pickerTenant();
    $this->actingAs($owner)->withSession(['workspace_uuid' => $workspace2->uuid])
        ->getJson('/campaigns/targets/contacts')
        ->assertNotFound();
});

it('más workspace listája nem jelenik meg a választóban', function () {
    [$other] = pickerTenant();
    ContactList::create(['name' => 'Idegen lista']);

    [$workspace, $user] = pickerTenant();
    ContactList::create(['name' => 'Saját lista']);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->getJson('/campaigns/targets/lists')
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.name', 'Saját lista');

    expect($other->getKey())->not->toBe($workspace->getKey());
});

it('a kampány oldala a KIVÁLASZTOTT célok nevét adja, nem a teljes listát', function () {
    [$workspace, $user] = pickerTenant();

    foreach (range(1, 30) as $i) {
        ContactList::create(['name' => "Lista {$i}"]);
    }
    $target = ContactList::query()->where('name', 'Lista 1')->firstOrFail();
    $excluded = ContactList::query()->where('name', 'Lista 2')->firstOrFail();
    $segment = Segment::create(['name' => 'Szegmens', 'definition' => ['match' => 'all', 'conditions' => []]]);

    $campaign = Campaign::create([
        'name' => 'Teszt', 'subject' => 'Tárgy',
        'target_lists' => [(string) $target->uuid],
        'target_segments' => [(string) $segment->uuid],
        'exclude_lists' => [(string) $excluded->uuid],
    ]);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/campaigns/'.$campaign->uuid)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('lists')
            ->missing('segments')
            ->where('selectedTargets.lists', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Lista 1', 'Lista 2'])
            ->where('selectedTargets.segments.0.name', 'Szegmens')
            ->where('targetCounts.lists', 30)
            ->where('targetCounts.segments', 1));
});
