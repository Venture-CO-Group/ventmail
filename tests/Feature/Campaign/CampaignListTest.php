<?php

declare(strict_types=1);

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 5. modul — KAMPÁNYLISTA: állapot-jelvények, szűrők, keresés, sor-statisztika.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->context = app(WorkspaceContext::class);
    $this->context->set($this->workspace);
    $this->user = StudioFixtures::user($this->workspace);
});

function listCampaign(string $name, string $status, array $counts = []): Campaign
{
    $campaign = Campaign::create(['name' => $name, 'subject' => 'T']);
    $campaign->forceFill(array_merge(['status' => $status], $counts))->save();

    return $campaign;
}

function listPage($test, string $query = '')
{
    return $test->actingAs($test->user)->withSession(['workspace_uuid' => $test->workspace->uuid])->get('/campaigns'.$query);
}

it('a sor statisztikája a meglévő analitikából jön: küldött, egyedi nyitás- és kattintás-arány', function () {
    $sent = listCampaign('Elküldött', 'sent', ['recipient_count' => 4, 'sent_count' => 4]);
    listCampaign('Vázlat', 'draft');

    $ws = (int) $this->workspace->getKey();
    $scope = 'campaign:'.$sent->uuid;

    StudioFixtures::engagement($ws, 'a@pelda.hu', now(), now(), $scope);
    StudioFixtures::engagement($ws, 'b@pelda.hu', now(), null, $scope);
    StudioFixtures::engagement($ws, 'c@pelda.hu', null, null, $scope);
    StudioFixtures::engagement($ws, 'd@pelda.hu', null, null, $scope);

    listPage($this)->assertOk()->assertInertia(fn ($page) => $page
        ->component('Campaign/Index')
        ->where('campaigns.1.name', 'Elküldött')
        ->where('campaigns.1.sent', 4)
        ->where('campaigns.1.openRate', 50)
        ->where('campaigns.1.clickRate', 25)
        ->where('campaigns.0.openRate', null));
});

it('állapot-szűrő és keresés — a `failed` a lezárt, de egyetlen levelet sem küldött kampány', function () {
    listCampaign('Ősz hírlevél', 'sent', ['recipient_count' => 3, 'sent_count' => 3]);
    listCampaign('Ősz elutasított', 'sent', ['recipient_count' => 3, 'sent_count' => 0, 'failed_count' => 3]);
    listCampaign('Tavasz', 'scheduled');
    listCampaign('Leállított', 'canceled');

    listPage($this, '?status=failed')->assertInertia(fn ($page) => $page
        ->has('campaigns', 1)
        ->where('campaigns.0.name', 'Ősz elutasított')
        ->where('campaigns.0.displayStatus', 'failed'));

    listPage($this, '?status=sent')->assertInertia(fn ($page) => $page
        ->has('campaigns', 1)
        ->where('campaigns.0.name', 'Ősz hírlevél'));

    listPage($this, '?search='.urlencode('Ősz'))->assertInertia(fn ($page) => $page->has('campaigns', 2));

    // Ismeretlen szűrő → minden.
    listPage($this, '?status=drop')->assertInertia(fn ($page) => $page->has('campaigns', 4)->where('filters.status', 'all'));
});

it('a lista tenant-szűrt', function () {
    listCampaign('Saját', 'draft');
    $this->context->runFor(Workspace::factory()->create(), fn () => listCampaign('Idegen', 'draft'));

    listPage($this)->assertInertia(fn ($page) => $page->has('campaigns', 1)->where('campaigns.0.name', 'Saját'));
});
