<?php

declare(strict_types=1);

use App\Modules\Audience\Models\Contact;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | Unified UI v2 · Audience (Figma): a kontaktlista állapot-chipekkel szűrhető — Mind,
 | Feliratkozott, Megerősítésre vár, Leiratkozott, Tisztított. A szűrés a SZERVEREN történik
 | (százezres tábla), ismeretlen állapot = nincs szűrés, nem hiba.
 */

function statusTenant(): array
{
    $workspace = Workspace::factory()->create();
    $user = StudioFixtures::user($workspace);
    app(WorkspaceContext::class)->set($workspace);

    foreach (['subscribed' => 3, 'pending' => 2, 'unsubscribed' => 1, 'cleaned' => 1] as $status => $count) {
        foreach (range(1, $count) as $i) {
            Contact::create(['email' => "{$status}-{$i}@pelda.hu", 'status' => $status]);
        }
    }

    return [$workspace, $user];
}

it('az állapot-szűrő csak az adott állapotú kontaktokat adja, és visszaadja a választást', function (string $status, int $expected) {
    [$workspace, $user] = statusTenant();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/audience?status='.$status)
        ->assertInertia(fn ($page) => $page
            ->where('status', $status)
            ->has('contacts.items', $expected)
            ->where('contacts.items.0.status', $status));
})->with([['subscribed', 3], ['pending', 2], ['unsubscribed', 1], ['cleaned', 1]]);

it('ismeretlen állapotnál nincs szűrés', function () {
    [$workspace, $user] = statusTenant();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/audience?status=deleted')
        ->assertInertia(fn ($page) => $page->where('status', null)->has('contacts.items', 7));
});

it('az állapot-szűrő és a keresés együtt hat', function () {
    [$workspace, $user] = statusTenant();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/audience?'.http_build_query(['status' => 'subscribed', 'search' => 'subscribed-2']))
        ->assertInertia(fn ($page) => $page->has('contacts.items', 1)->where('contacts.items.0.email', 'subscribed-2@pelda.hu'));
});
