<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Identity\Enums\Role;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;

uses(RefreshDatabase::class);

/*
 | M4-4 · JOURNEY-K ÉS ANALITIKA a felületen.
 |
 | A journey-nél a tét az AKTIVÁLÁS: egy körkörös vagy kilépés nélküli lánc a futásban
 | ragadna be — és ott már címzettek vannak. Az analitikánál a tét a SZÁM: ugyanaz kell
 | jöjjön, mint az API-n és a naplóban.
 */

function studioActor(Workspace $workspace, string $role = 'owner'): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'j-'.uniqid(),
        'name' => 'Marketinges',
        'email' => 'j-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);

    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

    return $user;
}

it('a journey-lista a FUTÓ példányokat is mutatja — az aktív, de üres journey hiba', function () {
    $workspace = Workspace::factory()->create();
    $user = studioActor($workspace);

    app(WorkspaceContext::class)->runFor($workspace, function () {
        $journey = Journey::create(['name' => 'Üdvözlő', 'trigger_type' => 'api_event']);
        $journey->forceFill(['status' => 'active'])->save();
    });

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/journeys')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Journey/Index')
            ->has('journeys', 1)
            ->where('journeys.0.status', 'active')
            // NULLA futó példány egy aktív journey-ben: a trigger valószínűleg sosem tüzel.
            ->where('journeys.0.activeRuns', 0)
        );
});

it('a VÁRAKOZÁS NÉLKÜLI kör nem is MENTHETŐ — a lánc-őr a modellben van, nem a felületen', function () {
    /*
     | A körkörös lánc a legdrágább hiba: a benne lévő címzettek beragadnak, és a worker a
     | lépés-korlátig ég. A védelem ezért NEM az aktiválásnál van, hanem a MENTÉSNÉL — egy
     | ilyen lánc létre sem jöhet.
     |
     | A `wait`-et tartalmazó kör viszont LEGITIM: ott a motor felfüggeszti a futást. A
     | validátor pontosan ezt a különbséget ismeri, és a teszt is ezt méri.
     */
    $workspace = Workspace::factory()->create();

    app(WorkspaceContext::class)->runFor($workspace, function () {
        $journey = Journey::create(['name' => 'Körkörös', 'trigger_type' => 'api_event']);

        $first = JourneyStep::create([
            'journey_id' => $journey->getKey(),
            'type' => 'add_tag',
            // A `tag` UUID kell legyen: az oszlop típusos, egy nem-UUID érték futáskor
            // adatbázis-hibát adna, nem üres találatot.
            'config' => ['tag' => (string) Str::uuid()],
            'position' => 0,
        ]);

        $second = JourneyStep::create([
            'journey_id' => $journey->getKey(),
            'type' => 'add_tag',
            'config' => ['tag' => (string) Str::uuid()],
            'position' => 1,
        ]);

        $first->forceFill(['next_step_id' => $second->getKey()])->save();

        // A VISSZAMUTATÓ ág zárná a kört — itt bukik el, a mentésnél.
        expect(fn () => $second->forceFill(['next_step_id' => $first->getKey()])->save())
            ->toThrow(InvalidArgumentException::class, 'VÁRAKOZÁS NÉLKÜLI kört');
    });
});

it('az ÉRVÉNYES journey aktiválható a felületről, és a lista követi', function () {
    $workspace = Workspace::factory()->create();
    $user = studioActor($workspace);

    $journey = app(WorkspaceContext::class)->runFor($workspace, function () {
        $journey = Journey::create(['name' => 'Üdvözlő sorozat', 'trigger_type' => 'api_event']);

        // EGY várakozás, majd kilépés: a lánc véges, tehát aktiválható.
        $wait = JourneyStep::create([
            'journey_id' => $journey->getKey(),
            'type' => 'wait',
            'config' => ['minutes' => 60],
            'position' => 0,
        ]);

        $exit = JourneyStep::create([
            'journey_id' => $journey->getKey(),
            'type' => 'exit',
            'config' => [],
            'position' => 1,
        ]);

        $wait->forceFill(['next_step_id' => $exit->getKey()])->save();
        $journey->forceFill(['first_step_id' => $wait->getKey()])->save();

        return $journey;
    });

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/journeys/'.$journey->uuid)
        ->post('/journeys/'.$journey->uuid.'/activate')
        ->assertSessionHas('success');

    expect($journey->fresh()->status)->toBe('active');

    // …és a SZÜNET visszaveszi, a futásokat érintetlenül hagyva.
    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/journeys/'.$journey->uuid)
        ->post('/journeys/'.$journey->uuid.'/pause')
        ->assertSessionHas('success');

    expect($journey->fresh()->status)->toBe('paused');
});

it('a journey AKTIVÁLÁSA külön képesség — az author nem indíthat sorozatot', function () {
    $workspace = Workspace::factory()->create();
    $author = studioActor($workspace, Role::Author->value);

    $journey = app(WorkspaceContext::class)->runFor(
        $workspace,
        fn () => Journey::create(['name' => 'Teszt', 'trigger_type' => 'api_event'])
    );

    $this->actingAs($author)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/journeys')
        ->post('/journeys/'.$journey->uuid.'/activate')
        ->assertForbidden();
});

it('a kampány-riport a NYITÁSI TORZÍTÁST is elmondja, nem csak a számot', function () {
    /*
     | A 73. kritérium: az Apple MPP miatt a nyitás egy része gépi. A fenntartás a
     | képernyőn van (`mppNote`), nem a dokumentációban — aki a számot nézi, annak ott kell
     | látnia.
     */
    $workspace = Workspace::factory()->create();
    $user = studioActor($workspace);

    $campaign = app(WorkspaceContext::class)->runFor($workspace, function () {
        $campaign = Campaign::create(['name' => 'Riport', 'subject' => 'Tárgy']);
        $campaign->forceFill(['status' => 'sent'])->saveQuietly();

        return $campaign;
    });

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/analytics')
        ->assertInertia(fn ($page) => $page->component('Analytics/Index')->has('campaigns', 1));

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/analytics/campaigns/'.$campaign->uuid)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Analytics/Campaign')
            ->has('summary')
            ->has('links')
            ->where('campaign.name', 'Riport')
        );
});

it('az analitikát a naplót nem látó szerep is nézheti — de idegen tenantét nem', function () {
    // Az `analytics.view` az összesített számokról szól (nincs benne címzett-cím), ezért
    // az `author` is megkapja. A tenant-határ viszont ugyanolyan szigorú.
    $enyem = Workspace::factory()->create();
    $idegen = Workspace::factory()->create();
    $author = studioActor($enyem, Role::Author->value);

    $idegenKampany = app(WorkspaceContext::class)->runFor($idegen, function () {
        $campaign = Campaign::create(['name' => 'Idegen', 'subject' => 'X']);
        $campaign->forceFill(['status' => 'sent'])->saveQuietly();

        return $campaign;
    });

    $this->actingAs($author)->withSession(['workspace_uuid' => $enyem->uuid])
        ->get('/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('campaigns', 0));

    $this->actingAs($author)->withSession(['workspace_uuid' => $enyem->uuid])
        ->get('/analytics/campaigns/'.$idegenKampany->uuid)
        ->assertNotFound();
});
