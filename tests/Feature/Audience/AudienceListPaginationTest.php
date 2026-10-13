<?php

declare(strict_types=1);

use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Segment;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · D284 — a KÖZÖNSÉG-képernyő listái és szegmensei SZERVEROLDALON lapozva és kereshetően
 | jönnek: száz lista mellett az oldal nem lehet 10 000 px magas, és a böngésző nem kap meg
 | minden sort.
 */

function paginationTenant(): array
{
    $workspace = Workspace::factory()->create();
    $user = StudioFixtures::user($workspace);
    app(WorkspaceContext::class)->set($workspace);

    return [$workspace, $user];
}

it('a listák lapozva jönnek (legfrissebb elöl), a teljes számmal', function () {
    [$workspace, $user] = paginationTenant();

    foreach (range(1, 23) as $i) {
        ContactList::create(['name' => sprintf('Lista %02d', $i)]);
    }

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/audience')
        ->assertInertia(fn ($page) => $page
            ->has('lists.items', 10)
            ->where('lists.items.0.name', 'Lista 23')
            ->where('lists.page', 1)
            ->where('lists.last', 3)
            ->where('lists.total', 23));

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/audience?lists_page=3')
        ->assertInertia(fn ($page) => $page->has('lists.items', 3)->where('lists.page', 3));
});

it('a listák és a szegmensek egymástól függetlenül kereshetők', function () {
    [$workspace, $user] = paginationTenant();

    ContactList::create(['name' => 'Hírlevél']);
    ContactList::create(['name' => 'Törzsvendégek']);
    Segment::create(['name' => 'Aktív vásárlók', 'definition' => ['match' => 'all', 'conditions' => []]]);
    Segment::create(['name' => 'Inaktívak', 'definition' => ['match' => 'all', 'conditions' => []]]);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/audience?'.http_build_query(['lists_search' => 'törzs', 'segments_search' => 'aktív']))
        ->assertInertia(fn ($page) => $page
            ->has('lists.items', 1)
            ->where('lists.items.0.name', 'Törzsvendégek')
            ->where('lists.search', 'törzs')
            ->where('segments.items', fn ($items) => collect($items)->pluck('name')->sort()->values()->all() === ['Aktív vásárlók', 'Inaktívak'])
            ->where('segments.search', 'aktív'));
});

it('a szegmensek is lapozva jönnek', function () {
    [$workspace, $user] = paginationTenant();

    foreach (range(1, 12) as $i) {
        Segment::create(['name' => "Szegmens {$i}", 'definition' => ['match' => 'all', 'conditions' => []]]);
    }

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/audience?segments_page=2')
        ->assertInertia(fn ($page) => $page->has('segments.items', 2)->where('segments.total', 12)->where('segments.last', 2));
});

it('a keresés LIKE-joker karaktere szó szerint értendő', function () {
    [$workspace, $user] = paginationTenant();

    ContactList::create(['name' => '100% hűség']);
    ContactList::create(['name' => 'Más lista']);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/audience?lists_search=%25')
        ->assertInertia(fn ($page) => $page->has('lists.items', 1)->where('lists.items.0.name', '100% hűség'));
});

it('a már létező listanév validációs hibát ad, nem 500-at (a DB-index tenantonként egyedi)', function () {
    [$workspace, $user] = paginationTenant();
    ContactList::create(['name' => 'Hírlevél']);
    $other = ContactList::create(['name' => 'Másik']);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/audience')
        ->post('/audience/lists', ['name' => 'Hírlevél'])
        ->assertRedirect('/audience')
        ->assertSessionHasErrors('name');

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/audience')
        ->post('/audience/lists/'.$other->uuid, ['name' => 'Hírlevél'])
        ->assertSessionHasErrors('name');

    // Más tenantban ugyanaz a név szabad.
    [$workspace2, $user2] = paginationTenant();
    $this->actingAs($user2)->withSession(['workspace_uuid' => $workspace2->uuid])
        ->from('/audience')
        ->post('/audience/lists', ['name' => 'Hírlevél'])
        ->assertSessionHasNoErrors();
});
