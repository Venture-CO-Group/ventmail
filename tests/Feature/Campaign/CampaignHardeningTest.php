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
use App\Modules\Campaign\Services\CampaignBatchDispatcher;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Campaign\Services\CampaignStateChanged;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Domains\Models\Domain;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Journey\Services\JourneyGraphInvalid;
use App\Modules\Journey\Services\JourneyPublisher;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Template\Models\SavedBlock;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Models\TemplateAsset;
use App\Modules\Template\Models\TemplateVersion;
use App\Modules\Template\Services\TemplateEditorSaver;
use App\Modules\Template\Services\TemplatePublishLocked;
use App\Modules\Template\Services\TemplateVersions;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\QueryBudget;
use App\Support\QueryTooExpensive;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // A zár a job timeoutja + 30 mp-ig él — a `retry_after` (960) ELŐTT lejár, így az
        // újrakiadott (meghalt workerű) job nem csendben dobódik el (review NIT).
        ->and($overlap->expiresAfter)->toBe($job->timeout + 30)
        ->and($overlap->expiresAfter)->toBeLessThan(config('queue.connections.redis_campaign_start.retry_after'));
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

/* ---------------------------------------------------------------- 3. review-kör: B1, S2, S3, R-S1 */

function r3Scheduled(): Campaign
{
    $campaign = hardCampaign([], 'later');
    $at = now('Europe/Budapest')->addDays(2)->setTime(9, 0)->format('Y-m-d H:i');
    $campaign->forceFill(['scheduled_local' => $at])->save();

    return app(CampaignLifecycle::class)->schedule($campaign, $at, 'Europe/Budapest');
}

function r3As($test, string $role)
{
    $user = StudioFixtures::user($test->workspace, $role);

    return $test->actingAs($user)->withSession(['workspace_uuid' => $test->workspace->uuid]);
}

it('B1 · szerző az AKTÍV kampány sablonját nem írhatja át — kézi mentés, autosave és verzió-visszaállítás egyaránt tiltott', function () {
    hardMember('a@pelda.hu');
    r3Scheduled();
    $template = $this->template;
    $before = $template->fresh()->blocks;

    r3As($this, 'author')->put('/templates/'.$template->uuid, [
        'name' => $template->name, 'subject' => 'Jóváhagyatlan', 'blocks' => $before,
    ])->assertSessionHas('error');

    r3As($this, 'author')->putJson('/templates/'.$template->uuid.'/autosave', ['blocks' => $before, 'name' => $template->name])
        ->assertForbidden()->assertJsonStructure(['message']);

    r3As($this, 'author')->postJson('/templates/'.$template->uuid.'/versions/1/restore')->assertForbidden();

    expect($template->fresh()->subject)->not->toBe('Jóváhagyatlan')
        ->and($template->fresh()->blocks)->toEqual($before);
});

it('B1 · az aktív journey (send_email) sablonja is publikálási jogot kér', function () {
    $journey = Journey::create(['name' => 'Üdvözlő', 'trigger_type' => TriggerType::FormSignup->value, 'trigger_config' => []]);
    $step = JourneyStep::create(['journey_id' => $journey->getKey(), 'position' => 0, 'type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'hirlevel@pelda.hu']]);
    $journey->forceFill(['status' => 'active', 'first_step_id' => $step->getKey()])->save();

    r3As($this, 'author')->putJson('/templates/'.$this->template->uuid.'/autosave', ['blocks' => $this->template->blocks, 'name' => $this->template->name])
        ->assertForbidden();
});

it('B1 · publikálási joggal (editor) az aktív sablon menthető; a nem használt sablont a szerző is mentheti', function () {
    hardMember('a@pelda.hu');
    r3Scheduled();

    r3As($this, 'editor')->putJson('/templates/'.$this->template->uuid.'/autosave', ['blocks' => $this->template->blocks, 'name' => $this->template->name])
        ->assertOk();

    $free = hardTemplate('Szabad', 'Nádor utca 1., 1051 Budapest');

    r3As($this, 'author')->putJson('/templates/'.$free->uuid.'/autosave', ['blocks' => $free->blocks, 'name' => $free->name])
        ->assertOk();
});

it('S2 · az elavult példányon indított unschedule / cancel nem írja felül a közben `sending`-re váltott állapotot', function () {
    hardMember('a@pelda.hu');
    $stale = r3Scheduled();

    // Közben az ütemező elindította.
    Campaign::query()->whereKey($stale->getKey())->update(['status' => 'sending']);

    expect(fn () => app(CampaignLifecycle::class)->unschedule($stale))->toThrow(RuntimeException::class)
        ->and(fn () => app(CampaignLifecycle::class)->cancel($stale))->toThrow(RuntimeException::class)
        ->and($stale->fresh()->status)->toBe('sending')
        ->and($stale->fresh()->finished_at)->toBeNull();
});

it('S2 · az elavult példányon indított pause nem ír a közben visszavont kampányra', function () {
    hardMember('a@pelda.hu');
    $stale = app(CampaignLifecycle::class)->start(hardCampaign());

    Campaign::query()->whereKey($stale->getKey())->update(['status' => 'canceled']);

    expect(fn () => app(CampaignLifecycle::class)->pause($stale))->toThrow(RuntimeException::class)
        ->and($stale->fresh()->status)->toBe('canceled');
});

it('S3 · a kampány indítása auditált: felületről a felhasználóval, ütemezőből `system` aktorral', function () {
    hardMember('a@pelda.hu');
    Queue::fake();

    $now = hardCampaign();
    hardAs($this)->post('/campaigns/'.$now->uuid.'/transition', ['action' => 'send'])->assertSessionHas('success');

    $row = AuditLog::query()->where('action', 'campaign.started')->where('auditable_id', $now->getKey())->firstOrFail();

    expect($row->user_id)->toBe($this->user->getKey())
        ->and($row->context['actor'] ?? null)->toBe('user');

    $later = hardCampaign([], 'later');
    app(CampaignLifecycle::class)->schedule($later, now()->addMinutes(5)->toDateTimeString(), 'UTC');
    $this->travel(10)->minutes();
    auth()->logout();

    $this->artisan('campaigns:dispatch-scheduled')->assertSuccessful();

    $row = AuditLog::query()->where('action', 'campaign.started')->where('auditable_id', $later->getKey())->firstOrFail();

    expect($row->user_id)->toBeNull()
        ->and($row->context['actor'] ?? null)->toBe('system');
});

it('R-S1 · ha a kötegek indítása KÖZBEN szüneteltetik, a batch_id nem íródik a kampányra, és a köteg törlődik', function () {
    hardMember('a@pelda.hu');

    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());
    $campaign->recipients()->create(['contact_id' => Contact::query()->value('id'), 'email' => 'a@pelda.hu']);

    // A dispatcher a `sending` példánnyal indul — az adatbázisban közben szüneteltették.
    Campaign::query()->whereKey($campaign->getKey())->update(['status' => 'paused']);

    $batchId = app(CampaignBatchDispatcher::class)->dispatch($campaign);

    expect($batchId)->not->toBeNull()
        ->and(Bus::findBatch($batchId)?->cancelled())->toBeTrue()
        ->and($campaign->fresh()->batch_id)->toBeNull()
        ->and($campaign->fresh()->status)->toBe('paused');
});

/* ---------------------------------------------------------------- 4. review-kör */

/**
 * PÁRHUZAMOS VÁLTOZÁS SZIMULÁLÁSA: az első olyan lekérdezés UTÁN, amelynek SQL-je minden
 * `$needles` elemet tartalmaz, egyszer lefut a `$then` (egy „másik kérés" írása).
 */
function r4After(array $needles, callable $then): void
{
    $fired = false;

    Event::listen(QueryExecuted::class, function ($query) use ($needles, $then, &$fired): void {
        if ($fired) {
            return;
        }

        foreach ($needles as $needle) {
            if (! str_contains(strtolower($query->sql), strtolower($needle))) {
                return;
            }
        }

        $fired = true;
        $then();
    });
}

it('R4-S1 · két párhuzamos folytatás (dupla kattintás, két fül) PONTOSAN EGY köteget indít', function () {
    hardMember('a@pelda.hu');
    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());

    Bus::fake();
    app(StartCampaign::class)->materialize($campaign->fresh());
    app(CampaignLifecycle::class)->pause($campaign->fresh());

    // Mindkét kérés a `paused` állapotot látta.
    $first = $campaign->fresh();
    $second = $campaign->fresh();

    app(CampaignLifecycle::class)->resume($first);
    app(CampaignLifecycle::class)->resume($second);

    // 1 az indításé + 1 a folytatásé — a második folytatás nem indít újat.
    Bus::assertBatchCount(2);
    expect($campaign->fresh()->status)->toBe('sending');
});

it('R4-S2 · az állapot-ütközés dedikált kivétel, a felhasználó nyelvén (nem magyar belső szöveg)', function () {
    app()->setLocale('en');
    hardMember('a@pelda.hu');
    $stale = r3Scheduled();
    Campaign::query()->whereKey($stale->getKey())->update(['status' => 'sending']);

    expect(fn () => app(CampaignLifecycle::class)->unschedule($stale))
        ->toThrow(CampaignStateChanged::class, __('studio.checklist.changed', [], 'en'));
});

it('R4 NIT · a felületi indítás a ZÁROLT sort hasonlítja: a betöltés és a zár között elindított kampány nem indul másodszor', function () {
    app()->setLocale('en');
    hardMember('a@pelda.hu');
    Queue::fake();
    $campaign = hardCampaign();

    // A kérés betölti a kampányt (`draft`), mielőtt a sorzárat felvenné — közben egy másik
    // kérés (vagy az ütemező) elindítja.
    r4After(['from "campaigns"', '"uuid" = ?'], fn () => DB::table('campaigns')->where('id', $campaign->getKey())->update(['status' => 'sending']));

    hardAs($this)->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])
        ->assertSessionHas('error', __('studio.checklist.changed', [], 'en'));

    Queue::assertNotPushed(StartCampaignJob::class);
});

it('R4 NIT · az ütemező FRISS példányon ellenőriz, és csak TÉNYLEGES váltás után auditál', function () {
    hardMember('a@pelda.hu');
    $campaign = hardCampaign([], 'later');
    app(CampaignLifecycle::class)->schedule($campaign, now()->addMinutes(5)->toDateTimeString(), 'UTC');
    Domain::query()->update(['status' => 'pending']);
    $this->travel(10)->minutes();

    // Az esedékes-lista után a felhasználó maga vette vissza vázlatba.
    r4After(['from "campaigns"', '"scheduled_at" <= ?'], fn () => DB::table('campaigns')->where('id', $campaign->getKey())->update(['status' => 'draft', 'scheduled_at' => null]));

    $this->artisan('campaigns:dispatch-scheduled')->assertSuccessful();

    expect($campaign->fresh()->status)->toBe('draft')
        ->and(AuditLog::query()->where('action', 'campaign.unscheduled_by_checklist')->exists())->toBeFalse();
});

it('R4 NIT · a lezárás a közben szüneteltetett kampányon csendes no-op (nincs failed job)', function () {
    hardMember('a@pelda.hu');
    $stale = app(CampaignLifecycle::class)->start(hardCampaign());
    Campaign::query()->whereKey($stale->getKey())->update(['status' => 'paused']);

    expect(app(CampaignLifecycle::class)->completeIfSending($stale))->toBeNull()
        ->and($stale->fresh()->status)->toBe('paused');
});

it('SEC4-S1 · a publikálási kapu a MENTŐ tranzakcióján belül, a sablon-sor zárja alatt is érvényes', function () {
    hardMember('a@pelda.hu');
    r3Scheduled();

    $author = StudioFixtures::user($this->workspace, 'author');
    $this->actingAs($author);

    expect(fn () => app(TemplateEditorSaver::class)->save($this->template, ['blocks' => $this->template->blocks], 'save'))
        ->toThrow(TemplatePublishLocked::class);

    $version = app(TemplateVersions::class)->snapshot($this->template->fresh(), 'save');

    if ($version !== null) {
        expect(fn () => app(TemplateVersions::class)->restore($this->template->fresh(), $version))
            ->toThrow(TemplatePublishLocked::class);
    }
});

it('SEC4-S1 · az indítás és az ütemezés a hivatkozott sablon-sorokat is zárolja (FOR SHARE)', function () {
    hardMember('a@pelda.hu');
    Queue::fake();
    $campaign = hardCampaign();
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });

    hardAs($this)->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])->assertSessionHas('success');

    expect(collect($sql)->contains(fn (string $q) => str_contains($q, 'from "templates"') && str_contains($q, 'for share')))->toBeTrue();
});

it('SEC4-S1 · a journey publikálása a send_email sablonokat zárolja (FOR SHARE)', function () {
    $journey = Journey::create(['name' => 'Vászon', 'trigger_type' => TriggerType::FormSignup->value, 'trigger_config' => []]);
    $node = fn (string $id, string $type, array $config, int $y) => ['id' => $id, 'type' => $type, 'position' => ['x' => 0, 'y' => $y], 'label' => '', 'config' => $config];
    $edge = fn (string $source, string $target) => ['id' => "e-{$source}-{$target}", 'source' => $source, 'sourceHandle' => 'out', 'target' => $target];

    $journey->forceFill(['graph' => [
        'nodes' => [
            $node('t', 'api_event', ['event' => 'signup'], 0),
            $node('send', 'send_email', ['template' => (string) $this->template->uuid, 'from_email' => 'hirlevel@pelda.hu'], 100),
            $node('end', 'exit', [], 200),
        ],
        'edges' => [$edge('t', 'send'), $edge('send', 'end')],
    ]])->save();

    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });

    try {
        app(JourneyPublisher::class)->publish($journey->fresh());
    } catch (JourneyGraphInvalid $e) {
        // A gráf-formátum a modul 6 dolga; a zárnak a validálás ELŐTT kell megtörténnie.
    }

    expect(collect($sql)->contains(fn (string $q) => str_contains($q, 'from "templates"') && str_contains($q, 'for share')))->toBeTrue();
});

it('SEC4-S2 · köteg-generáció CAS: ha közben másik köteg íródott a kampányra, a saját köteg törlődik', function () {
    hardMember('a@pelda.hu');
    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());
    $campaign->recipients()->create(['contact_id' => Contact::query()->value('id'), 'email' => 'a@pelda.hu']);

    // Ez a dispatcher a `null` generációt látta; közben egy folytatás a saját kötegét írta fel.
    $seen = $campaign->fresh();
    Campaign::query()->whereKey($campaign->getKey())->update(['batch_id' => 'masik-koteg', 'batch_generation' => 1]);

    Bus::fake();
    $batchId = app(CampaignBatchDispatcher::class)->dispatch($seen);

    expect($campaign->fresh()->batch_id)->toBe('masik-koteg')
        ->and($batchId)->not->toBe('masik-koteg');

    Bus::assertBatched(fn ($batch) => $batch->name === 'campaign:'.$campaign->uuid);
});

it('SEC4-S2 · a másik köteg által már elküldött címzett nem számolódik másodszor (sent_count, variáns)', function () {
    hardMember('a@pelda.hu');
    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());

    Bus::fake();
    app(StartCampaign::class)->materialize($campaign->fresh());
    $recipient = $campaign->recipients()->firstOrFail();

    // A címzett-sorok beolvasása UTÁN egy párhuzamos köteg elküldte és beszámolta ugyanezt a címzettet.
    r4After(['from "campaign_recipients"', '"status" = ?'], function () use ($recipient, $campaign): void {
        DB::table('campaign_recipients')->where('id', $recipient->getKey())->update(['status' => 'sent', 'sent_at' => now()]);
        DB::table('campaigns')->where('id', $campaign->getKey())->update(['sent_count' => 1]);
    });

    (new SendCampaignChunk((string) $campaign->uuid, (int) $this->workspace->getKey(), [$recipient->getKey()]))
        ->handle(app(WorkspaceContext::class), app(SendMessage::class));

    expect($campaign->fresh()->sent_count)->toBe(1);
});

it('N-d · az aktív sablonban hivatkozott kép törlése publikálási jogot kér', function () {
    hardMember('a@pelda.hu');
    $asset = TemplateAsset::create(['driver' => 'local', 'path' => 'assets/x.png', 'url' => 'https://cdn.pelda.hu/assets/x-'.uniqid().'.png', 'mime' => 'image/png', 'size' => 10, 'width' => 10, 'height' => 10]);
    $free = TemplateAsset::create(['driver' => 'local', 'path' => 'assets/y.png', 'url' => 'https://cdn.pelda.hu/assets/y-'.uniqid().'.png', 'mime' => 'image/png', 'size' => 10, 'width' => 10, 'height' => 10]);

    $blocks = $this->template->blocks;
    $blocks[0]['children'][0]['children'][] = ['type' => 'image', 'attrs' => ['src' => $asset->url, 'alt' => 'Kép']];
    $this->template->forceFill(['blocks' => $blocks])->saveQuietly();
    r3Scheduled();

    r3As($this, 'author')->deleteJson('/template-assets/'.$asset->uuid)->assertForbidden();
    r3As($this, 'author')->deleteJson('/template-assets/'.$free->uuid)->assertOk();

    expect(TemplateAsset::query()->whereKey($asset->getKey())->exists())->toBeTrue();
});

it('R4-S3 · a zárolt sablon szerkesztője a tiltás okát kapja (csak olvasható mód); publikálónak nincs zár', function () {
    hardMember('a@pelda.hu');
    r3Scheduled();

    r3As($this, 'author')->get('/templates/'.$this->template->uuid)->assertOk()
        ->assertInertia(fn ($page) => $page->where('writeDenial', fn ($value) => is_string($value) && $value !== ''));

    r3As($this, 'editor')->get('/templates/'.$this->template->uuid)->assertOk()
        ->assertInertia(fn ($page) => $page->where('writeDenial', null));
});

/* ---------------------------------------------------------------- 5. review-kör */

it('S2(r5) · pause → resume a köteg-építés KÖZBEN: a régebbi építés veszít, a folytatás kötege él és minden pending sort lefed', function () {
    hardMember('a@pelda.hu');
    hardMember('b@pelda.hu');
    Queue::fake();

    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());
    app(StartCampaign::class)->materialize($campaign->fresh());

    // R1: egy köteg-építés a szüneteltetés ELŐTTI generációval indul.
    $r1Campaign = $campaign->fresh();
    $r2Campaign = null;

    // A pending-sorok beolvasása után: szüneteltetés, majd a folytatás ÁLLAPOTVÁLTÁSA — a folytatás
    // saját köteg-építése (R2) még „úton van", és csak R1 UTÁN ír. (R1 jobjai a szünet alatt
    // korán kiléptek volna — a címzettjeiket csak R2 viheti tovább.)
    r4After(['from "campaign_recipients"', 'order by "id"'], function () use ($campaign, &$r2Campaign): void {
        app(CampaignLifecycle::class)->pause($campaign->fresh());
        DB::table('campaigns')->where('id', $campaign->getKey())->update(['status' => 'sending']);
        $r2Campaign = $campaign->fresh();
    });

    $dispatcher = app(CampaignBatchDispatcher::class);
    $r1 = $dispatcher->dispatch($r1Campaign);
    $r2 = $dispatcher->dispatch($r2Campaign);

    $fresh = $campaign->fresh();

    expect($fresh->status)->toBe('sending')
        // A régebbi (szüneteltetés előtti) generáció veszít és törlődik…
        ->and(Bus::findBatch((string) $r1)?->cancelled())->toBeTrue()
        // …a folytatás kötege él, és MINDEN pending sort lefed.
        ->and((string) $fresh->batch_id)->toBe((string) $r2)
        ->and(Bus::findBatch((string) $r2)?->cancelled())->toBeFalse()
        ->and(Bus::findBatch((string) $r2)?->totalJobs)->toBe((int) ceil($fresh->recipients()->where('status', 'pending')->count() / CampaignBatchDispatcher::CHUNK))
        ->and($fresh->recipients()->where('status', 'pending')->count())->toBe(2);
});

it('N1(r5) · a lezárás a számlálókat a címzett-sorokból egyezteti (kampány és variáns)', function () {
    hardMember('a@pelda.hu');
    hardMember('b@pelda.hu');
    hardMember('c@pelda.hu');

    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());
    $variant = $campaign->variants()->create(['label' => 'A', 'subject' => 'A tárgy', 'weight' => 100]);

    $ids = Contact::query()->orderBy('email')->pluck('id', 'email');
    $campaign->recipients()->create(['contact_id' => $ids['a@pelda.hu'], 'email' => 'a@pelda.hu', 'campaign_variant_id' => $variant->getKey()]);
    $campaign->recipients()->create(['contact_id' => $ids['b@pelda.hu'], 'email' => 'b@pelda.hu', 'campaign_variant_id' => $variant->getKey()]);
    $campaign->recipients()->create(['contact_id' => $ids['c@pelda.hu'], 'email' => 'c@pelda.hu']);
    DB::table('campaign_recipients')->where('campaign_id', $campaign->getKey())->whereIn('email', ['a@pelda.hu', 'b@pelda.hu'])->update(['status' => 'sent']);
    DB::table('campaign_recipients')->where('campaign_id', $campaign->getKey())->where('email', 'c@pelda.hu')->update(['status' => 'skipped', 'skip_reason' => 'NO_CONSENT']);

    // Egy worker a címzett-sorok commitja után, a számlálás előtt meghalt: a számlálók alul vannak.
    DB::table('campaigns')->where('id', $campaign->getKey())->update(['sent_count' => 1, 'failed_count' => 0]);

    app(CampaignLifecycle::class)->complete($campaign->fresh());

    $fresh = $campaign->fresh();

    expect($fresh->status)->toBe('sent')
        ->and($fresh->sent_count)->toBe(2)
        ->and($fresh->failed_count)->toBe(1)
        ->and((int) $variant->fresh()->sent_count)->toBe(2);
});

it('N2(r5) · a sablon törlése a sablon-sort zárolja, és a zár alatt kérdezi a használatokat', function () {
    $template = hardTemplate('Törlendő', 'Nádor utca 1., 1051 Budapest');
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });

    hardAs($this)->delete('/templates/'.$template->uuid)->assertRedirect();

    $lock = collect($sql)->search(fn (string $q) => str_contains($q, 'from "templates"') && str_contains($q, 'for update'));
    $usage = collect($sql)->search(fn (string $q) => str_contains($q, 'from "campaigns"'));

    expect($lock)->not->toBeFalse()
        ->and($usage)->not->toBeFalse()
        ->and($lock)->toBeLessThan($usage);
});

it('N3(r5) · a kép törlése az AKTÍV sablon korábbi VERZIÓJÁBAN vagy mentett blokkban hivatkozott képre is publikálási jogot kér', function () {
    hardMember('a@pelda.hu');
    $inVersion = TemplateAsset::create(['driver' => 'local', 'path' => 'assets/v.png', 'url' => 'https://cdn.pelda.hu/assets/v-'.uniqid().'.png', 'mime' => 'image/png', 'size' => 10, 'width' => 10, 'height' => 10]);
    $inBlock = TemplateAsset::create(['driver' => 'local', 'path' => 'assets/b.png', 'url' => 'https://cdn.pelda.hu/assets/b-'.uniqid().'.png', 'mime' => 'image/png', 'size' => 10, 'width' => 10, 'height' => 10]);

    // A sablon egy KORÁBBI verziója hivatkozza a képet (visszaállítással visszajönne).
    $blocks = $this->template->blocks;
    $withImage = $blocks;
    $withImage[0]['children'][0]['children'][] = ['type' => 'image', 'attrs' => ['src' => $inVersion->url, 'alt' => 'Kép']];
    TemplateVersion::create(['template_id' => $this->template->getKey(), 'number' => 99, 'name' => 'v99', 'reason' => 'save', 'blocks' => $withImage, 'subject' => 'x', 'content_hash' => hash('sha256', 'v99')]);
    r3Scheduled();

    SavedBlock::create(['name' => 'Fejléc', 'kind' => 'block', 'block' => ['type' => 'image', 'attrs' => ['src' => $inBlock->url, 'alt' => 'Logó']]]);

    r3As($this, 'author')->deleteJson('/template-assets/'.$inVersion->uuid)->assertForbidden();
    r3As($this, 'author')->deleteJson('/template-assets/'.$inBlock->uuid)->assertForbidden();
    r3As($this, 'editor')->deleteJson('/template-assets/'.$inBlock->uuid)->assertOk();
});

/* ---------------------------------------------------------------- 6. review-kör */

it('S1(r6) · a lezárás (egyeztetés) UTÁN érkező köteg-számlálás nem ír rá a számlálókra', function () {
    hardMember('a@pelda.hu');
    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());
    $variant = $campaign->variants()->create(['label' => 'A', 'subject' => 'A tárgy', 'weight' => 100]);
    $campaign->recipients()->create(['contact_id' => Contact::query()->value('id'), 'email' => 'a@pelda.hu', 'campaign_variant_id' => $variant->getKey()]);
    DB::table('campaign_recipients')->where('campaign_id', $campaign->getKey())->update(['status' => 'sent']);

    app(CampaignLifecycle::class)->complete($campaign->fresh());

    // Egy lassú köteg a címzett-sorait még a lezárás ELŐTT commitolta, a számlálóit csak most írná.
    $chunk = new SendCampaignChunk((string) $campaign->uuid, (int) $this->workspace->getKey(), []);
    $bump = new ReflectionMethod($chunk, 'bumpCounters');
    $bump->invoke($chunk, $campaign->fresh(), ['sent_count' => 1, 'failed_count' => 0], [(int) $variant->getKey() => 1]);

    expect($campaign->fresh()->sent_count)->toBe(1)
        ->and((int) $variant->fresh()->sent_count)->toBe(1);
});

it('S1(r6) · küldés alatt (és visszavont kampányon) a köteg-számlálás továbbra is összeadódik', function () {
    hardMember('a@pelda.hu');
    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());
    $chunk = new SendCampaignChunk((string) $campaign->uuid, (int) $this->workspace->getKey(), []);
    $bump = new ReflectionMethod($chunk, 'bumpCounters');

    $bump->invoke($chunk, $campaign->fresh(), ['sent_count' => 2, 'failed_count' => 1], []);
    app(CampaignLifecycle::class)->cancel($campaign->fresh());
    $bump->invoke($chunk, $campaign->fresh(), ['sent_count' => 1, 'failed_count' => 0], []);

    expect($campaign->fresh()->sent_count)->toBe(3)
        ->and($campaign->fresh()->failed_count)->toBe(1);
});

it('S2(r6) · a variáns-egyeztetés a kampányra szűkít (a lekérdezés a campaign_id-t is köti)', function () {
    hardMember('a@pelda.hu');
    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });

    app(CampaignLifecycle::class)->complete($campaign->fresh());

    expect(collect($sql)->contains(fn (string $q) => str_contains($q, 'update "campaign_variants"')
        && str_contains($q, 'r.campaign_id = campaign_variants.campaign_id')))->toBeTrue();
});

it('N3(r6) · a VISSZAVONÁS közben épülő köteg veszít és törlődik', function () {
    hardMember('a@pelda.hu');
    Queue::fake();
    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());
    $campaign->recipients()->create(['contact_id' => Contact::query()->value('id'), 'email' => 'a@pelda.hu']);
    $building = $campaign->fresh();

    r4After(['from "campaign_recipients"', 'order by "id"'], fn () => app(CampaignLifecycle::class)->cancel($campaign->fresh()));

    $batchId = app(CampaignBatchDispatcher::class)->dispatch($building);

    expect($campaign->fresh()->status)->toBe('canceled')
        ->and($campaign->fresh()->batch_id)->toBeNull()
        ->and(Bus::findBatch((string) $batchId)?->cancelled())->toBeTrue();
});

it('N3(r6) · a materializáló job kötege a közbeni pause + resume után veszít; a folytatás kötege él', function () {
    hardMember('a@pelda.hu');
    hardMember('b@pelda.hu');
    Queue::fake();
    $campaign = app(CampaignLifecycle::class)->start(hardCampaign());
    $live = null;

    // A materializálás a felírás után indítja a kötegét; a pending-sorok beolvasása után a felhasználó
    // szüneteltet és folytat (a folytatás teljesen lefut, a saját kötegével).
    r4After(['from "campaign_recipients"', 'order by "id"'], function () use ($campaign, &$live): void {
        app(CampaignLifecycle::class)->pause($campaign->fresh());
        app(CampaignLifecycle::class)->resume($campaign->fresh());
        $live = (string) $campaign->fresh()->batch_id;
    });

    app(StartCampaign::class)->materialize($campaign->fresh());

    $fresh = $campaign->fresh();

    expect($fresh->status)->toBe('sending')
        ->and((string) $fresh->batch_id)->toBe((string) $live)
        ->and(Bus::findBatch((string) $live)?->cancelled())->toBeFalse()
        ->and(Bus::findBatch((string) $live)?->totalJobs)->toBe(1)
        ->and($fresh->recipients()->where('status', 'pending')->count())->toBe(2)
        // A job_batches-ban a materializálás kötege (a másik) törölt.
        ->and(DB::table('job_batches')->where('name', 'campaign:'.$campaign->uuid)->where('id', '!=', $live)->whereNull('cancelled_at')->exists())->toBeFalse();
});
