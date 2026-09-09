<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignVariant;
use App\Modules\Campaign\Services\VariantAssigner;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | M4-6 · A/B VÁLTOZAT LÉTREHOZÁSA A FELÜLETRŐL.
 |
 | A variánsokat eddig a képernyő csak MUTATTA — létrehozni API-ból lehetett, vagyis az
 | A/B teszt gyakorlatilag fejlesztői funkció volt.
 */

function variantUser(Workspace $workspace, string $role = 'owner'): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'ab-'.uniqid(),
        'name' => 'Marketinges',
        'email' => 'ab-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);

    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

    return $user;
}

it('a felületről felvett változat SÚLYA a kiosztásban is érvényesül', function () {
    /*
     | A súly SZÁZALÉK, és az összegnek pontosan 100-nak kell lennie (`assertReady`). A
     | `VariantAssigner` pozíció → variáns térképet ad: 100 címzettnél a 30-as súlyú
     | változat PONTOSAN 30 pozíciót kap, nem „körülbelül annyit".
     */
    $workspace = Workspace::factory()->create();
    $user = variantUser($workspace);

    $campaign = app(WorkspaceContext::class)->runFor($workspace, fn () => Campaign::create([
        'name' => 'Nyári akció',
        'subject' => 'A',
    ]));

    app(WorkspaceContext::class)->runFor($workspace, fn () => CampaignVariant::create([
        'campaign_id' => $campaign->getKey(),
        'label' => 'A',
        'subject' => 'Eredeti tárgy',
        'weight' => 30,
    ]));

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/campaigns/'.$campaign->uuid)
        ->post('/campaigns/'.$campaign->uuid.'/variants', [
            'label' => 'B',
            'subject' => 'Másik tárgy',
            'weight' => 70,
        ])
        ->assertRedirect('/campaigns/'.$campaign->uuid);

    app(WorkspaceContext::class)->runFor($workspace, function () use ($campaign) {
        $plan = app(VariantAssigner::class)->plan($campaign->fresh(), 100);

        expect($plan)->toHaveCount(100);

        $byLabel = [];

        foreach ($plan as $variantId) {
            $label = (string) CampaignVariant::query()->whereKey($variantId)->value('label');
            $byLabel[$label] = ($byLabel[$label] ?? 0) + 1;
        }

        expect($byLabel)->toBe(['A' => 30, 'B' => 70]);
    });

    // A FELÜLET kiírja az összeget: az indítás 100-at követel, és ezt nem az indítás
    // hibaüzenetéből kell megtudni.
    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/campaigns/'.$campaign->uuid)
        ->assertInertia(fn ($page) => $page->where('campaign.variantWeightTotal', 100));
});

it('az elgépelt súlyú változat TÖRÖLHETŐ — különben a képernyő zsákutca', function () {
    /*
     | Az indítás pontosan 100-as összeget követel. Törlés nélkül egy elgépelt súlyt csak
     | API-ból lehetett volna javítani — a felületen a kampány elindíthatatlan maradt volna.
     */
    $workspace = Workspace::factory()->create();
    $user = variantUser($workspace);

    $campaign = app(WorkspaceContext::class)->runFor($workspace, fn () => Campaign::create([
        'name' => 'Javítható',
        'subject' => 'A',
    ]));

    $variant = app(WorkspaceContext::class)->runFor($workspace, fn () => CampaignVariant::create([
        'campaign_id' => $campaign->getKey(),
        'label' => 'A',
        'subject' => 'Elgépelt',
        'weight' => 90,
    ]));

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/campaigns/'.$campaign->uuid)
        ->delete('/campaigns/'.$campaign->uuid.'/variants/'.$variant->uuid)
        ->assertRedirect('/campaigns/'.$campaign->uuid);

    app(WorkspaceContext::class)->runFor($workspace, function () use ($campaign) {
        expect(CampaignVariant::query()->where('campaign_id', $campaign->getKey())->count())->toBe(0);
    });
});

it('KÜLDÉS KÖZBEN nem vehető fel új változat', function () {
    /*
     | A kiosztás a küldés ELEJÉN dől el. Egy menet közben érkező variáns a már kiküldött
     | felével összehasonlíthatatlan lenne — az A/B eredmény hazudna.
     */
    $workspace = Workspace::factory()->create();
    $user = variantUser($workspace);

    // A `status` SZÁNDÉKOSAN nem tömegesen kitölthető: az állapotot a `CampaignLifecycle`
    // írja, nem a hívó. A fixtúra ezért `forceFill`-lel állítja be a küldés közbeni sort.
    $campaign = app(WorkspaceContext::class)->runFor($workspace, function () {
        $campaign = Campaign::create(['name' => 'Fut', 'subject' => 'A']);
        $campaign->forceFill(['status' => 'sending'])->save();

        return $campaign;
    });

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/campaigns/'.$campaign->uuid)
        ->post('/campaigns/'.$campaign->uuid.'/variants', [
            'label' => 'B',
            'subject' => 'Késői',
            'weight' => 50,
        ])
        ->assertSessionHas('error');

    app(WorkspaceContext::class)->runFor($workspace, function () use ($campaign) {
        expect(CampaignVariant::query()->where('campaign_id', $campaign->getKey())->count())->toBe(0);
    });
});

it('a szerkesztő képernyő a SÚLYT mutatja, és csak piszkozatnál enged felvenni', function () {
    $workspace = Workspace::factory()->create();
    $user = variantUser($workspace);

    $campaign = app(WorkspaceContext::class)->runFor($workspace, function () {
        $campaign = Campaign::create(['name' => 'Teszt', 'subject' => 'A']);

        CampaignVariant::create([
            'campaign_id' => $campaign->getKey(),
            'label' => 'A',
            'subject' => 'Eredeti',
            'weight' => 40,
        ]);

        return $campaign;
    });

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/campaigns/'.$campaign->uuid)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Campaign/Edit')
            ->where('campaign.editable', true)
            ->where('campaign.variants.0.weight', 40)
        );
});

it('a változat-felvétel a campaign.create képességhez kötött', function () {
    $workspace = Workspace::factory()->create();
    $analyst = variantUser($workspace, 'analyst');

    $campaign = app(WorkspaceContext::class)->runFor($workspace, fn () => Campaign::create([
        'name' => 'Teszt',
        'subject' => 'A',
    ]));

    $this->actingAs($analyst)->withSession(['workspace_uuid' => $workspace->uuid])
        ->post('/campaigns/'.$campaign->uuid.'/variants', ['label' => 'B', 'subject' => 'X', 'weight' => 50])
        ->assertForbidden();
});
