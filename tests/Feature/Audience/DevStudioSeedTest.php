<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 5. modul — a Studio böngésző-tesztjeinek seed-végpontja SOHA nem érhető el, ha a
 | dev-belépő ki van kapcsolva (élesben ez a helyzet): a kontroller maga is ellenőriz.
 */
it('a dev-seed végpont 404, ha a dev-belépő ki van kapcsolva', function () {
    $workspace = Workspace::factory()->create();
    $user = StudioFixtures::user($workspace);

    config(['identity.dev_login_enabled' => false]);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->getJson('/dev/seed-studio?run=x')
        ->assertNotFound();
});

it('a dev-seed végpont ÉLES környezetben akkor is 404, ha a dev-belépő kapcsoló be van kapcsolva', function () {
    $workspace = Workspace::factory()->create();
    $user = StudioFixtures::user($workspace);

    config(['identity.dev_login_enabled' => true]);
    $this->app['env'] = 'production';

    try {
        $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
            ->getJson('/dev/seed-studio?run=x')
            ->assertNotFound();
    } finally {
        $this->app['env'] = 'testing';
    }
});
