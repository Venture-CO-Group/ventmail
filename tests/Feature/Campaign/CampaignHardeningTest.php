<?php

declare(strict_types=1);

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaign\Actions\StartCampaign;
use App\Modules\Campaign\Jobs\RefreshCampaignStats;
use App\Modules\Campaign\Jobs\SendCampaignChunk;
use App\Modules\Campaign\Jobs\StartCampaignJob;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Domains\Models\Domain;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\QueryBudget;
use App\Support\QueryTooExpensive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 5. modul — a kampány-oldali review-javítások (RB1/S4, RS1, RS2/N5, RS4, RS6, RS7).
 */

beforeEach(function () {
    FakeDriver::reset();
    Cache::flush();

    $this->workspace = Workspace::factory()->create();
    $this->context = app(WorkspaceContext::class);
    $this->context->set($this->workspace);
    $this->user = StudioFixtures::user($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->list = ContactList::create(['name' => 'Hírlevél']);
    $this->template = hardTemplate('Rendes', 'Nádor utca 1., 1051 Budapest');
});

function hardTemplate(string $name, string $address): Template
{
    return Template::create([
        'name' => $name,
        'marketing' => true,
        'blocks' => [
            ['type' => 'section', 'children' => [['type' => 'column', 'children' => [['type' => 'text', 'attrs' => ['content' => 'Szia!']]]]]],
            ['type' => 'section', 'children' => [['type' => 'column', 'children' => [
                ['type' => 'footer', 'attrs' => ['company' => 'Kft', 'address' => $address, 'content' => 'x', 'unsubscribe_label' => 'Le', 'preferences_label' => 'Beá']],
            ]]]],
        ],
    ]);
}

function hardMember(string $email, array $attributes = []): Contact
{
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request($email));

    $contact = Contact::create(array_merge(['email' => $email, 'status' => 'subscribed'], $attributes));
    $contact->lists()->attach(test()->list, ['status' => 'subscribed', 'subscribed_at' => now()]);

    return $contact;
}

function hardCampaign(array $extra = [], string $mode = 'now'): Campaign
{
    $campaign = Campaign::create(array_merge([
        'name' => 'Ősz '.uniqid(),
        'subject' => 'Őszi hírek',
        'from_email' => 'hirlevel@pelda.hu',
        'template_id' => test()->template->getKey(),
        'target_lists' => [(string) test()->list->uuid],
        'timezone' => 'Europe/Budapest',
    ], $extra));

    $campaign->forceFill(['send_mode' => $mode])->save();

    return $campaign;
}

function hardAs($test)
{
    return $test->actingAs($test->user)->withSession(['workspace_uuid' => $test->workspace->uuid]);
}

/* ---------------------------------------------------------------- RB1 / S4 */

it('RB1 · a küldés a kérésben csak LEFOGLAL; a materializálás és a kötegek a campaign sorra mennek', function () {
    Queue::fake();
    hardMember('a@pelda.hu');

    $campaign = hardCampaign();

    hardAs($this)->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])->assertSessionHas('success');

    expect($campaign->fresh()->status)->toBe('sending')
        ->and($campaign->fresh()->recipients()->count())->toBe(0);

    Queue::assertPushedOn('campaign-start', StartCampaignJob::class);
});

it('RS7 · a DUPLA indítás nem indít második materializálást', function () {
    Queue::fake();
    hardMember('a@pelda.hu');

    $campaign = hardCampaign();

    hardAs($this)->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])->assertSessionHas('success');
    hardAs($this)->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])->assertSessionHas('error');

    Queue::assertPushed(StartCampaignJob::class, 1);
});

it('RB1 · a materializáló job lefut: címzettek + kötegek; a hibás job a kampányt SZÜNETELTETI, a folytatás újra materializál', function () {
    hardMember('a@pelda.hu');

    $campaign = hardCampaign();
    app(CampaignLifecycle::class)->start($campaign);

    (new StartCampaignJob((string) $campaign->uuid, (int) $this->workspace->getKey()))->failed(new RuntimeException('teszt'));

    expect($campaign->fresh()->status)->toBe('paused');

    Queue::fake();
    app(CampaignLifecycle::class)->resume($campaign->fresh());

    Queue::assertPushed(StartCampaignJob::class, 1);
    expect($campaign->fresh()->status)->toBe('sending');

    Queue::fake()->except([]);
    (new StartCampaignJob((string) $campaign->uuid, (int) $this->workspace->getKey()))->handle(app(WorkspaceContext::class), app(StartCampaign::class));

    expect($campaign->fresh()->recipients()->count())->toBe(1);
});

/* ---------------------------------------------------------------- RS1 */

it('RS1 · az ütemezett kampány szerkesztése a checklisttel ÚJRAÜTEMEZ', function () {
    hardMember('a@pelda.hu');

    $campaign = hardCampaign([], 'later');
    $first = now('Europe/Budapest')->addDays(2)->setTime(9, 0)->format('Y-m-d H:i');
    $campaign->forceFill(['scheduled_local' => $first])->save();
    app(CampaignLifecycle::class)->schedule($campaign, $first, 'Europe/Budapest');

    $second = now('Europe/Budapest')->addDays(3)->setTime(10, 30)->format('Y-m-d H:i');

    hardAs($this)->put('/campaigns/'.$campaign->uuid, [
        'name' => $campaign->name, 'subject' => 'Új tárgy', 'fromEmail' => 'hirlevel@pelda.hu',
        'templateUuid' => (string) $this->template->uuid, 'targetLists' => [(string) $this->list->uuid],
        'sendMode' => 'later', 'scheduledLocal' => $second, 'timezone' => 'Europe/Budapest',
    ])->assertSessionHasNoErrors();

    $fresh = $campaign->fresh();

    expect($fresh->status)->toBe('scheduled')
        ->and($fresh->scheduledLocal()->format('Y-m-d H:i'))->toBe($second)
        ->and($fresh->subject)->toBe('Új tárgy');
});

it('RS1 · ha a szerkesztés után a checklist nem teljesül, az ütemezés VISSZAVONÓDIK', function () {
    hardMember('a@pelda.hu');

    $campaign = hardCampaign([], 'later');
    $at = now('Europe/Budapest')->addDays(2)->setTime(9, 0)->format('Y-m-d H:i');
    $campaign->forceFill(['scheduled_local' => $at])->save();
    app(CampaignLifecycle::class)->schedule($campaign, $at, 'Europe/Budapest');

    $placeholder = hardTemplate('Helyőrző', (string) __('editor.defaults.footerAddress'));

    hardAs($this)->put('/campaigns/'.$campaign->uuid, [
        'name' => $campaign->name, 'subject' => 'x', 'fromEmail' => 'hirlevel@pelda.hu',
        'templateUuid' => (string) $placeholder->uuid, 'targetLists' => [(string) $this->list->uuid],
        'sendMode' => 'later', 'scheduledLocal' => $at, 'timezone' => 'Europe/Budapest',
    ])->assertSessionHas('error');

    expect($campaign->fresh()->status)->toBe('draft')
        ->and($campaign->fresh()->scheduled_at)->toBeNull();
});

it('RS1 · az ütemező a hiányos kampányt nem indítja el, hanem visszaveszi vázlatba', function () {
    hardMember('a@pelda.hu');

    $campaign = hardCampaign([], 'later');
    app(CampaignLifecycle::class)->schedule($campaign, now()->addMinutes(5)->toDateTimeString(), 'UTC');

    Domain::query()->update(['status' => 'pending']);
    $this->travel(10)->minutes();

    $this->artisan('campaigns:dispatch-scheduled')->assertSuccessful();

    expect($campaign->fresh()->status)->toBe('draft')
        ->and(Message::query()->count())->toBe(0);
});

/* ---------------------------------------------------------------- RS2 / N5, RS7 */

it('RS2/N5 · a tárgyból minden vezérlőkarakter kiesik, és legfeljebb 255 karakter', function () {
    hardMember('a@pelda.hu', ['first_name' => "Anna\r\nBcc: tamado@evil.example\x00\x07".str_repeat('é', 400)]);

    $campaign = hardCampaign(['subject' => 'Szia {{ contact.first_name }}!']);

    hardAs($this)->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])->assertSessionHas('success');

    $subject = (string) Message::query()->firstOrFail()->subject;

    expect($subject)->not->toMatch('/[\x00-\x1F\x7F]/u')
        ->and(mb_strlen($subject))->toBeLessThanOrEqual(255)
        ->and($subject)->toStartWith('Szia Anna Bcc: tamado@evil.example');
});

it('RS7 · újrapróbáláskor megváltozott render-adattal a levél nem megy ki másodszor (replay)', function () {
    $contact = hardMember('a@pelda.hu', ['first_name' => 'Anna']);

    $campaign = hardCampaign(['subject' => 'Szia {{ contact.first_name }}']);
    app(StartCampaign::class)($campaign);

    $recipient = $campaign->recipients()->firstOrFail();
    expect($recipient->status)->toBe('sent');

    // A köteg újrafut (pl. a worker a számlálók írása előtt halt meg), közben a név változott.
    $recipient->forceFill(['status' => 'pending'])->save();
    $campaign->forceFill(['status' => 'sending'])->save();
    $contact->forceFill(['first_name' => 'Anna Mária'])->save();

    (new SendCampaignChunk((string) $campaign->uuid, (int) $this->workspace->getKey(), [$recipient->getKey()]))
        ->handle(app(WorkspaceContext::class), app(SendMessage::class));

    expect($recipient->fresh()->status)->toBe('sent')
        ->and(Message::query()->count())->toBe(1);
});

it('RS7 · a journey ugyanazt a beágyazott szegmens-szemantikát kapja (szegmensbe lépés)', function () {
    hardMember('budapest@pelda.hu', ['attributes' => ['city' => 'Budapest']]);
    hardMember('szeged@pelda.hu', ['attributes' => ['city' => 'Szeged']]);

    $segment = Segment::create(['name' => 'Budapest', 'definition' => ['match' => 'all', 'rules' => [
        ['group' => ['match' => 'any', 'rules' => [
            ['field' => 'attr:city', 'op' => 'is', 'value' => 'Budapest'],
            ['field' => 'attr:city', 'op' => 'is', 'value' => 'Pécs'],
        ]]],
    ]]]);

    $journey = Journey::create([
        'name' => 'Belépés',
        'trigger_type' => TriggerType::SegmentEntered->value,
        'trigger_config' => ['segment' => (string) $segment->uuid],
    ]);
    $step = JourneyStep::create(['journey_id' => $journey->getKey(), 'type' => 'exit', 'config' => [], 'position' => 0]);
    $journey->forceFill(['status' => 'active', 'first_step_id' => $step->getKey()])->save();

    $this->artisan('journeys:detect-segment-entries')->assertSuccessful();

    expect(JourneyRun::query()->pluck('email')->all())->toBe(['budapest@pelda.hu']);
});

/* ---------------------------------------------------------------- RS4, RS6 */

it('RS4 · a kampányoldal becslése a checklistből jön (egy feloldás), a consent-szám gyorsítótárazott', function () {
    hardMember('a@pelda.hu');
    $campaign = hardCampaign();

    hardAs($this)->get('/campaigns/'.$campaign->uuid)->assertInertia(fn ($page) => $page
        ->where('estimate', 1)
        ->where('checklist.sections.0.estimate', 1));

    // A gyorsítótárazott consent-szám: egy új, megerősített tag a TTL-en belül nem változtat.
    hardMember('b@pelda.hu');

    hardAs($this)->get('/campaigns/'.$campaign->uuid)->assertInertia(fn ($page) => $page
        ->where('checklist.checks.2.confirmed', 1));

    // A célzás változása (más hash) friss számot ad.
    $campaign->forceFill(['exclude_lists' => [(string) ContactList::create(['name' => 'Üres'])->uuid]])->save();

    hardAs($this)->get('/campaigns/'.$campaign->uuid)->assertInertia(fn ($page) => $page
        ->where('checklist.checks.2.confirmed', 2));
});

it('RS6 · a kampánylista statisztikája gyorsítótárból jön; hiányzó érték esetén háttér-job tölti', function () {
    Queue::fake();

    $campaign = Campaign::create(['name' => 'Elküldött', 'subject' => 'T']);
    $campaign->forceFill(['status' => 'sent', 'recipient_count' => 2, 'sent_count' => 2])->save();

    StudioFixtures::engagement((int) $this->workspace->getKey(), 'a@pelda.hu', now(), now(), 'campaign:'.$campaign->uuid);
    StudioFixtures::engagement((int) $this->workspace->getKey(), 'b@pelda.hu', null, null, 'campaign:'.$campaign->uuid);

    hardAs($this)->get('/campaigns')->assertInertia(fn ($page) => $page->where('campaigns.0.openRate', null));

    Queue::assertPushed(RefreshCampaignStats::class);

    (new RefreshCampaignStats((string) $campaign->uuid, (int) $this->workspace->getKey()))->handle(app(WorkspaceContext::class));

    hardAs($this)->get('/campaigns')->assertInertia(fn ($page) => $page
        ->where('campaigns.0.openRate', 50)
        ->where('campaigns.0.clickRate', 50));
});

/* ---------------------------------------------------------------- 2. review-kör: BN1, BN2, SN2, SN3, NIT */

it('BN1 · a materializáló job saját kapcsolaton fut: retry_after > a job timeoutja, külön supervisor, átfedés nélkül', function () {
    $job = new StartCampaignJob('uuid', 1);

    expect($job->queue)->toBe('campaign-start')
        ->and(config('queue.connections.redis_campaign_start.retry_after'))->toBeGreaterThanOrEqual($job->timeout + 60)
        ->and(config('queue.connections.redis_campaign_start.queue'))->toBe('campaign-start');

    $supervisor = config('horizon.environments.production.campaign-start');

    expect($supervisor['connection'])->toBe('redis_campaign_start')
        ->and($supervisor['queue'])->toBe(['campaign-start'])
        ->and($supervisor['timeout'])->toBeGreaterThanOrEqual($job->timeout)
        ->and($supervisor['timeout'])->toBeLessThan(config('queue.connections.redis_campaign_start.retry_after'))
        // A KÜLDŐ supervisor nem fogyasztja: egy 15 perces materializálás nem foglal küldő-workert.
        ->and(config('horizon.environments.production.sending.queue'))->not->toContain('campaign-start');

    foreach (['production', 'local', 'testing'] as $env) {
        expect(config("horizon.environments.{$env}"))->toHaveKey('campaign-start');
    }

    $overlap = collect($job->middleware())->first(fn ($m) => $m instanceof WithoutOverlapping);

    expect($overlap)->not->toBeNull()
        ->and($overlap->key)->toBe('uuid')
        ->and($overlap->releaseAfter)->toBeNull()
        ->and($overlap->expiresAfter)->toBeGreaterThanOrEqual($job->timeout);
});

it('BN1 · a materializálás közben SZÜNETELTETETT kampányra nem indul köteg és nem jön új pending sor', function () {
    hardMember('a@pelda.hu');
    hardMember('b@pelda.hu');

    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());

    // A job a `sending` modellel indult, közben a felhasználó szüneteltetett.
    Campaign::query()->whereKey($campaign->getKey())->update(['status' => 'paused']);

    Bus::fake();
    app(StartCampaign::class)->materialize($campaign);

    Bus::assertNothingBatched();

    $fresh = $campaign->fresh();

    expect($fresh->status)->toBe('paused')
        ->and($fresh->batch_id)->toBeNull()
        ->and($fresh->materialized_at)->toBeNull()
        ->and($fresh->recipients()->where('status', 'pending')->count())->toBe(0);
});

it('BN1 · a materializálás közben VISSZAVONT kampányon nem marad pending címzett', function () {
    hardMember('a@pelda.hu');

    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());
    Campaign::query()->whereKey($campaign->getKey())->update(['status' => 'canceled']);

    Bus::fake();
    app(StartCampaign::class)->materialize($campaign);

    Bus::assertNothingBatched();
    expect($campaign->fresh()->status)->toBe('canceled')
        ->and($campaign->fresh()->recipients()->where('status', 'pending')->count())->toBe(0);
});

it('BN2 · szerző (campaign.publish nélkül) az ütemezett kampányt nem ütemezheti át: a szerkesztés vázlatba veszi, auditálva', function () {
    hardMember('a@pelda.hu');

    $campaign = hardCampaign([], 'later');
    $first = now('Europe/Budapest')->addDays(2)->setTime(9, 0)->format('Y-m-d H:i');
    $campaign->forceFill(['scheduled_local' => $first])->save();
    app(CampaignLifecycle::class)->schedule($campaign, $first, 'Europe/Budapest');

    $author = StudioFixtures::user($this->workspace, 'author');
    $second = now('Europe/Budapest')->addDays(3)->setTime(10, 30)->format('Y-m-d H:i');

    $this->actingAs($author)->withSession(['workspace_uuid' => $this->workspace->uuid])->put('/campaigns/'.$campaign->uuid, [
        'name' => $campaign->name, 'subject' => 'Szerző tárgya', 'fromEmail' => 'hirlevel@pelda.hu',
        'templateUuid' => (string) $this->template->uuid, 'targetLists' => [(string) $this->list->uuid],
        'sendMode' => 'later', 'scheduledLocal' => $second, 'timezone' => 'Europe/Budapest',
    ])->assertSessionHas('error', __('studio.checklist.unscheduledNoPublish'));

    $fresh = $campaign->fresh();

    expect($fresh->status)->toBe('draft')
        ->and($fresh->scheduled_at)->toBeNull()
        ->and($fresh->subject)->toBe('Szerző tárgya')
        ->and(AuditLog::query()->where('action', 'campaign.unscheduled_by_edit')->exists())->toBeTrue();
});

it('SN2 · RÉSZLEGES materializálás után a folytatás újra materializál (nem csonka közönségre küld)', function () {
    hardMember('a@pelda.hu');
    hardMember('b@pelda.hu');
    hardMember('c@pelda.hu');

    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());

    // Az első köteg beíródott, aztán a job meghalt: van címzett-sor, de nincs `materialized_at`.
    $campaign->recipients()->create(['contact_id' => Contact::query()->where('email', 'a@pelda.hu')->value('id'), 'email' => 'a@pelda.hu']);
    app(CampaignLifecycle::class)->pause($campaign->fresh());

    Queue::fake();
    app(CampaignLifecycle::class)->resume($campaign->fresh());

    Queue::assertPushed(StartCampaignJob::class, 1);
    expect($campaign->fresh()->status)->toBe('sending');

    Queue::fake()->except([]);
    (new StartCampaignJob((string) $campaign->uuid, (int) $this->workspace->getKey()))->handle(app(WorkspaceContext::class), app(StartCampaign::class));

    $fresh = $campaign->fresh();

    expect($fresh->recipients()->count())->toBe(3)
        ->and($fresh->recipient_count)->toBe(3)
        ->and($fresh->materialized_at)->not->toBeNull();
});

it('SN2 · TELJES materializálás után a folytatás nem materializál újra, hanem a pending sorokra indít kötegeket', function () {
    hardMember('a@pelda.hu');

    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());

    Bus::fake();
    app(StartCampaign::class)->materialize($campaign->fresh());
    app(CampaignLifecycle::class)->pause($campaign->fresh());

    Queue::fake();
    app(CampaignLifecycle::class)->resume($campaign->fresh());

    Queue::assertNotPushed(StartCampaignJob::class);
    Bus::assertBatchCount(2);
});

it('SN3 · a checklist becslése KERETEZETT: túl költséges célzásnál „becslés nem elérhető", az oldal betölt és a küldés nem blokkolódik', function () {
    hardMember('a@pelda.hu');
    $campaign = hardCampaign();

    app()->instance(QueryBudget::class, new class extends QueryBudget
    {
        public function run(callable $work, ?int $milliseconds = null): mixed
        {
            throw new QueryTooExpensive;
        }
    });

    hardAs($this)->get('/campaigns/'.$campaign->uuid)->assertOk()->assertInertia(fn ($page) => $page
        ->where('estimate', null)
        ->where('checklist.sections.0.estimate', null)
        ->where('checklist.sections.0.estimateUnavailable', true)
        ->where('checklist.checks.2.unavailable', true)
        ->where('checklist.ready', true));

    Queue::fake();
    hardAs($this)->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])->assertSessionHas('success');
    Queue::assertPushed(StartCampaignJob::class, 1);
});

it('NIT · a tárgyból a C1 vezérlőkarakterek és a sor-/bekezdés-elválasztó is kiesik', function () {
    hardMember('a@pelda.hu', ['first_name' => "Anna\u{0085}Bcc\u{2028}x\u{2029}y\u{009F}z"]);

    $campaign = hardCampaign(['subject' => 'Szia {{ contact.first_name }}!']);

    hardAs($this)->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])->assertSessionHas('success');

    $subject = (string) Message::query()->firstOrFail()->subject;

    expect($subject)->not->toMatch('/[\x{0080}-\x{009F}\x{2028}\x{2029}]/u')
        ->and($subject)->toBe('Szia Anna Bcc x y z!');
});
