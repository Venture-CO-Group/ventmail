<?php

declare(strict_types=1);

use App\Modules\Audience\Actions\SubmitForm;
use App\Modules\Audience\Data\FormSubmission;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\Form;
use App\Modules\Audience\Services\AudienceInsights;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 5. modul — KÖZÖNSÉG-DASHBOARD: növekedés, forrás, engagement.
 |
 | A számoknak a SAJÁT tenant adataiból kell jönniük, napokra pontosan a workspace
 | időzónájában — és a végpont alakja chart-könyvtártól független (a 8. modul ECharts-a
 | ugyanezt olvassa).
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->context = app(WorkspaceContext::class);
    $this->context->set($this->workspace);
    $this->insights = app(AudienceInsights::class);
});

it('NÖVEKEDÉS: napi új / leiratkozott / nettó, üres napokkal kitöltve', function () {
    $this->travelTo(now()->setTime(12, 0));

    StudioFixtures::contact('ma1@pelda.hu');
    StudioFixtures::contact('ma2@pelda.hu');
    $tegnapi = StudioFixtures::contact('tegnap@pelda.hu');
    Contact::query()->whereKey($tegnapi->getKey())->update(['created_at' => now()->subDay()]);

    $leiratkozott = StudioFixtures::contact('le@pelda.hu', ['status' => 'unsubscribed']);
    Contact::query()->whereKey($leiratkozott->getKey())->update([
        'created_at' => now()->subDays(40),
        'unsubscribed_at' => now(),
    ]);

    $growth = $this->insights->growth(7, 'UTC');

    expect($growth)->toHaveCount(7);

    $today = end($growth);
    $yesterday = $growth[5];

    expect($today)->toBe(['date' => now()->toDateString(), 'new' => 2, 'unsubscribed' => 1, 'net' => 1])
        ->and($yesterday['new'])->toBe(1)
        ->and($growth[0]['new'])->toBe(0);
});

it('a napi bontás a workspace IDŐZÓNÁJÁBAN történik', function () {
    // 23:30 UTC = másnap 01:30 Budapesten (nyári idő): a kontakt a budapesti „holnapra" esik.
    $this->travelTo(Carbon::parse('2026-07-10 23:30:00', 'UTC'));

    StudioFixtures::contact('ejfel@pelda.hu');

    $utc = collect($this->insights->growth(7, 'UTC'))->firstWhere('new', 1);
    $budapest = collect($this->insights->growth(7, 'Europe/Budapest'))->firstWhere('new', 1);

    expect($utc['date'])->toBe('2026-07-10')
        ->and($budapest['date'])->toBe('2026-07-11');
});

it('FORRÁS-bontás: import, űrlap, API, kézi — a v1.1 előtti sornál a consent forrása a tartalék', function () {
    StudioFixtures::contact('import@pelda.hu', ['source' => 'import']);
    StudioFixtures::contact('urlap@pelda.hu', ['source' => 'form']);
    StudioFixtures::contact('api@pelda.hu', ['source' => 'api']);
    StudioFixtures::contact('kezi1@pelda.hu', ['source' => 'manual']);
    StudioFixtures::contact('regi@pelda.hu');
    StudioFixtures::consent('regi@pelda.hu', 'pending', 'import');
    StudioFixtures::contact('ismeretlen@pelda.hu');

    expect($this->insights->sources())->toBe([
        ['key' => 'import', 'count' => 2],
        ['key' => 'form', 'count' => 1],
        ['key' => 'api', 'count' => 1],
        ['key' => 'manual', 'count' => 2],
    ]);
});

it('az ŰRLAP-beküldés `form` forrású kontaktot hoz létre', function () {
    $form = Form::create(['name' => 'F', 'consent_text' => 'Kérek hírlevelet.']);

    app(SubmitForm::class)($form, new FormSubmission(
        email: 'urlapos@pelda.hu',
        firstName: null,
        lastName: null,
        attributes: [],
        honeypot: null,
        elapsedSeconds: 10,
        ip: '203.0.113.5',
        userAgent: 'teszt',
    ));

    expect(Contact::query()->where('email', 'urlapos@pelda.hu')->value('source'))->toBe('form');
});

it('ENGAGEMENT-szintek: aktív, inaktív, sosem nyitott, még nem kapott levelet', function () {
    $ws = (int) $this->workspace->getKey();

    foreach (['aktiv', 'inaktiv', 'soha', 'nemkapott'] as $name) {
        StudioFixtures::contact("{$name}@pelda.hu");
    }

    StudioFixtures::engagement($ws, 'aktiv@pelda.hu', now()->subDays(5));
    StudioFixtures::engagement($ws, 'inaktiv@pelda.hu', now()->subDays(200));
    StudioFixtures::engagement($ws, 'soha@pelda.hu');

    // Egy MÁSIK workspace friss nyitása ugyanarra a címre nem teheti aktívvá.
    StudioFixtures::engagement((int) Workspace::factory()->create()->getKey(), 'nemkapott@pelda.hu', now());

    expect($this->insights->engagement())->toBe([
        ['key' => 'active', 'count' => 1],
        ['key' => 'inactive', 'count' => 1],
        ['key' => 'never_opened', 'count' => 1],
        ['key' => 'not_mailed', 'count' => 1],
    ]);
});

it('a végpont JSON-t ad, tenant-szűrten, engedélylistás ablakkal', function () {
    $user = StudioFixtures::user($this->workspace);

    StudioFixtures::contact('sajat@pelda.hu', ['source' => 'form']);

    $other = Workspace::factory()->create();
    $this->context->runFor($other, fn () => StudioFixtures::contact('idegen@pelda.hu', ['source' => 'form']));

    $this->actingAs($user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->getJson('/audience/insights?days=7')
        ->assertOk()
        ->assertJsonPath('days', 7)
        ->assertJsonPath('totals.total', 1)
        ->assertJsonCount(7, 'growth')
        ->assertJsonPath('sources.1', ['key' => 'form', 'count' => 1])
        ->assertJsonStructure(['growth' => [['date', 'new', 'unsubscribed', 'net']], 'engagement' => [['key', 'count']]]);

    // Ismeretlen ablak → az alapértelmezett 30 nap, nem tetszőleges hosszú lekérdezés.
    $this->actingAs($user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->getJson('/audience/insights?days=100000')
        ->assertOk()
        ->assertJsonPath('days', 30);
});

it('a dashboard-végpont az audience.manage képességhez kötött', function () {
    $analyst = StudioFixtures::user($this->workspace, 'analyst');

    $this->actingAs($analyst)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->getJson('/audience/insights')
        ->assertForbidden();
});
