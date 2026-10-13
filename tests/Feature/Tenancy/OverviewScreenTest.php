<?php

declare(strict_types=1);

use App\Modules\Audience\Models\Contact;
use App\Modules\Campaign\Jobs\RefreshCampaignStats;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Domains\Models\Domain;
use App\Modules\Logs\Models\DomainDailyStat;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Support\QueryBudget;
use App\Support\QueryTooExpensive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AnalyticsFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · D284 — AZ ÁTTEKINTÉS a mockup szerint: KPI-sor sparkline-nal, legutóbbi kampányok,
 | kézbesíthetőség-kártya, teendők. Minden szám a MEGLÉVŐ szolgáltatásokból jön (analitika-
 | szekció, kampány-sorstatisztika, domain-állapot) — workspace-hez kötve, QueryBudget alatt.
 */

function overviewGet(Workspace $workspace, $user)
{
    return test()->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])->get('/dashboard');
}

function overviewStat(Domain $domain, int $daysAgo, array $values): void
{
    DomainDailyStat::query()->create(['domain_id' => $domain->getKey(), 'day' => now()->subDays($daysAgo)->toDateString(), ...$values]);
}

it('a KPI-sor a küldött / kézbesítési / megnyitási arányt és az aktív kontaktokat adja, sparkline-sorozattal és előző időszakkal', function () {
    Queue::fake();
    [$workspace, $user] = AnalyticsFixtures::tenant();
    $domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    overviewStat($domain, 2, ['sent' => 100, 'delivered' => 98, 'bounced' => 2, 'opened' => 49, 'clicked' => 10]);
    overviewStat($domain, 1, ['sent' => 100, 'delivered' => 100, 'bounced' => 0, 'opened' => 41, 'clicked' => 8]);
    overviewStat($domain, 40, ['sent' => 50, 'delivered' => 50, 'opened' => 10]); // előző időszak

    Contact::create(['email' => 'a@pelda.hu', 'status' => 'subscribed', 'subscribed_at' => now()->subDays(3)]);
    Contact::create(['email' => 'b@pelda.hu', 'status' => 'subscribed', 'subscribed_at' => now()->subDays(60)]);

    overviewGet($workspace, $user)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('kpis.available', true)
            ->where('kpis.days', 30)
            ->where('kpis.sent.value', 200)
            ->where('kpis.sent.previous', 50)
            ->where('kpis.deliveryRate.value', 99)
            ->where('kpis.openRate.value', fn ($value) => abs((float) $value - 45.5) < 0.1)
            ->where('kpis.activeContacts.value', 2)
            ->where('kpis.activeContacts.added', 1)
            ->has('kpis.sent.series', 30)
            ->has('kpis.activeContacts.series', 30)
        );
});

it('a legutóbbi kampányok táblája az öt legfrissebbet adja, a felületi állapottal és a gyorsítótárazott arányokkal', function () {
    Queue::fake();
    [$workspace, $user] = AnalyticsFixtures::tenant();

    $old = AnalyticsFixtures::campaign(['name' => 'Régi']);
    $campaigns = collect(range(1, 5))->map(fn ($i) => AnalyticsFixtures::campaign(['name' => "Friss {$i}", 'recipient_count' => 10, 'sent_count' => 10]));
    $failed = $campaigns->last();
    $failed->forceFill(['sent_count' => 0])->saveQuietly();

    Cache::put(RefreshCampaignStats::cacheKey((string) $campaigns->first()->uuid), ['delivered' => 10, 'opened' => 5, 'clicked' => 1], 60);

    overviewGet($workspace, $user)
        ->assertInertia(fn ($page) => $page
            ->has('campaigns', 5)
            ->where('campaigns.0.uuid', (string) $failed->uuid)
            ->where('campaigns.0.displayStatus', 'failed')
            ->where('campaigns.4.uuid', (string) $campaigns->first()->uuid)
            ->where('campaigns.4.openRate', 50)
            ->where('campaigns.4.recipients', 10)
            ->where('campaigns', fn ($rows) => collect($rows)->pluck('name')->doesntContain('Régi'))
        );

    expect($old)->toBeInstanceOf(Campaign::class);
});

it('a kézbesíthetőség-kártya a bounce/panasz arányt és a domain-rekordok összesített állapotát adja', function () {
    Queue::fake();
    [$workspace, $user] = AnalyticsFixtures::tenant();
    $domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
    $domain->forceFill(['spf_status' => 'verified', 'dkim_status' => 'verified', 'dmarc_status' => 'failed', 'tracking_enabled' => true, 'tracking_status' => 'pending'])->save();
    overviewStat($domain, 1, ['sent' => 1000, 'delivered' => 990, 'bounced' => 10, 'complained' => 1]);

    overviewGet($workspace, $user)
        ->assertInertia(fn ($page) => $page
            ->where('deliverability.bounceRate', 1)
            ->where('deliverability.complaintRate', 0.1)
            ->where('deliverability.records.spf', 'verified')
            ->where('deliverability.records.dkim', 'verified')
            ->where('deliverability.records.dmarc', 'failed')
            ->where('deliverability.records.tracking', 'pending')
            ->where('deliverability.healthy', false)
        );
});

it('a teendők a vázlatokat, a hitelesítetlen domaineket, a megerősítetlen kontaktokat és a dead-lettereket számolják, linkkel', function () {
    Queue::fake();
    [$workspace, $user] = AnalyticsFixtures::tenant();

    AnalyticsFixtures::campaign(['status' => 'draft']);
    AnalyticsFixtures::campaign(['status' => 'draft']);
    Domain::create(['name' => 'fuggo.hu', 'status' => 'pending']);
    Contact::create(['email' => 'p@pelda.hu', 'status' => 'pending']);
    ['endpoint' => $endpoint] = WebhookEndpoint::issue('Hook', 'https://pelda.hu/hook', ['delivered']);
    WebhookDelivery::create(['webhook_endpoint_id' => $endpoint->getKey(), 'event_type' => 'delivered', 'payload' => [], 'attempt' => 5, 'status' => 'dead_letter', 'dead_lettered_at' => now()]);

    overviewGet($workspace, $user)
        ->assertInertia(fn ($page) => $page
            ->where('todos', fn ($todos) => collect($todos)->keyBy('key')->map(fn ($todo) => [$todo['count'], $todo['href']])->all() === [
                'drafts' => [2, '/campaigns?status=draft'],
                'unverifiedDomains' => [1, '/domains'],
                'pendingContacts' => [1, '/audience'],
                'deadLetters' => [1, '/webhooks'],
            ])
        );
});

it('a szerep szerint rejtett blokkok null-t kapnak (az áttekintés nem kiskapu)', function () {
    Queue::fake();
    [$workspace, $analyst] = AnalyticsFixtures::tenant('analyst');
    AnalyticsFixtures::campaign(['status' => 'draft']);

    overviewGet($workspace, $analyst)
        ->assertInertia(fn ($page) => $page
            ->where('campaigns', null)
            ->has('kpis.sent')
            ->where('todos', fn ($todos) => collect($todos)->pluck('key')->doesntContain('drafts'))
        );
});

it('más workspace adata nem szivárog az áttekintésbe', function () {
    Queue::fake();
    [$other] = AnalyticsFixtures::tenant();
    $foreignDomain = Domain::create(['name' => 'idegen.hu', 'status' => 'verified', 'verified_at' => now()]);
    overviewStat($foreignDomain, 1, ['sent' => 999, 'delivered' => 999]);
    AnalyticsFixtures::campaign(['name' => 'Idegen kampány']);

    [$workspace, $user] = AnalyticsFixtures::tenant();

    overviewGet($workspace, $user)
        ->assertInertia(fn ($page) => $page
            ->where('kpis.sent.value', 0)
            ->has('campaigns', 0)
        );

    expect($other->getKey())->not->toBe($workspace->getKey());
});

it('keret-túllépésnél a KPI-blokk „nem elérhető", nem 500', function () {
    Queue::fake();
    [$workspace, $user] = AnalyticsFixtures::tenant();

    app()->instance(QueryBudget::class, new class extends QueryBudget
    {
        public function run(callable $work, ?int $milliseconds = null): mixed
        {
            throw new QueryTooExpensive('teszt');
        }
    });

    overviewGet($workspace, $user)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('kpis.available', false));

    expect(app(WorkspaceContext::class))->not->toBeNull();
});
