<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Api\Services\ApiReference;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | v1.1 · 7. modul — az appon belüli fejlesztői dokumentáció (D177): az OpenAPI-szerződésből
 | generált referencia, a route-ok VALÓDI scope-jával.
 */

function docsUser(Workspace $workspace, Role $role): User
{
    $user = User::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'roles' => [$role->value]]);

    return $user;
}

it('a referencia a szerződésből jön, a scope-ot a route adja', function () {
    $reference = app(ApiReference::class)->build();
    $operations = collect($reference['operations'])->keyBy(fn ($op) => $op['method'].' '.$op['path']);

    expect($reference['baseUrl'])->toContain('/v1')
        ->and($operations['POST /messages']['scope'])->toBe('messages:send')
        ->and(collect($operations['POST /messages']['parameters'])->pluck('name'))->toContain('Idempotency-Key')
        ->and(collect($operations['POST /messages']['body'])->pluck('name'))->toContain('from')
        ->and($operations['GET /events']['scope'])->toBe('events:read')
        ->and($operations['DELETE /suppressions/{email}']['scope'])->toBe('suppressions:write')
        ->and($operations['GET /templates']['scope'] ?? null)->not->toBeNull();
});

it('a dokumentáció képernyő a fejlesztőnek és az elemzőnek nyílik, a szerzőnek nem', function () {
    $this->withoutVite();
    $workspace = Workspace::factory()->create();

    foreach ([Role::Developer, Role::Analyst] as $role) {
        $this->actingAs(docsUser($workspace, $role))->withSession(['workspace_uuid' => $workspace->uuid])
            ->get('/developers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Docs')
                ->where('webhook.sample.test', true)
                ->where('webhook.events', ['delivered', 'opened', 'clicked', 'bounced', 'complained', 'unsubscribed'])
                ->where('sandbox.testKeyPrefix', 'vm_test_')
                ->has('reference.operations')
            );
    }

    $this->actingAs(docsUser($workspace, Role::Author))->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/developers')->assertForbidden();
});

it('az allowlist tartalma nem kerül a dokumentációba', function () {
    $this->withoutVite();
    config()->set('sending.allowlist', ['seed-titok@pelda.hu']);
    $workspace = Workspace::factory()->create();

    $this->actingAs(docsUser($workspace, Role::Developer))->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/developers')->assertOk()->assertDontSee('seed-titok@pelda.hu');
});
