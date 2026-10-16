<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
 | UNIFIED UI v2 · AZ APP-HÉJ megosztott propjai.
 |
 | A felső sáv KÖRNYEZETJELZŐJE minden képernyőn ugyanott áll (Foundations: „egy hely,
 | egy komponens, tónus a kockázat szerint"), ezért a küldési mód nem csak a Dashboard
 | propja, hanem a héjé. Az appváltó (→ ventHub) csak akkor jelenik meg, ha a ventHub
 | URL-je be van állítva — amíg a ventHub nem él, a gomb nem vezethet sehová.
 */

function shellActor(): array
{
    $workspace = Workspace::factory()->create();
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'shell-'.uniqid(),
        'name' => 'Héj Teszt',
        'email' => 'shell-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);
    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => ['owner']]);

    return [$user, $workspace];
}

it('a héj minden képernyőn megkapja a küldési módot', function () {
    config(['sending.live' => false, 'sending.allowlist_enforced' => true, 'sending.marketing_enabled' => false]);
    [$user, $workspace] = shellActor();

    $this->actingAs($user)->withSession(['workspace_uuid' => (string) $workspace->uuid])
        ->get('/profile')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sendingMode.live', false)
            ->where('sendingMode.allowlistEnforced', true)
            ->where('sendingMode.marketingEnabled', false));
});

it('élesben, allowlist nélkül, marketinggel a mód nem korlátozott', function () {
    config(['sending.live' => true, 'sending.allowlist_enforced' => false, 'sending.marketing_enabled' => true]);
    [$user, $workspace] = shellActor();

    $this->actingAs($user)->withSession(['workspace_uuid' => (string) $workspace->uuid])
        ->get('/profile')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sendingMode.live', true)
            ->where('sendingMode.allowlistEnforced', false)
            ->where('sendingMode.marketingEnabled', true));
});

it('az appváltó URL-je a konfigurációból jön, és üresen null', function () {
    [$user, $workspace] = shellActor();

    config(['identity.hub_url' => null]);
    $this->actingAs($user)->withSession(['workspace_uuid' => (string) $workspace->uuid])
        ->get('/profile')->assertInertia(fn (AssertableInertia $page) => $page->where('hubUrl', null));

    config(['identity.hub_url' => 'https://hub.ventureco.app']);
    $this->actingAs($user)->withSession(['workspace_uuid' => (string) $workspace->uuid])
        ->get('/profile')->assertInertia(fn (AssertableInertia $page) => $page->where('hubUrl', 'https://hub.ventureco.app'));
});
