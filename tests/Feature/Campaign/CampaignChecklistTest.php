<?php

declare(strict_types=1);

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignVariant;
use App\Modules\Campaign\Services\CampaignChecklist;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 5. modul — KAMPÁNY CHECKLIST.
 |
 | A küldés gombja csak akkor él, ha minden szakasz kész ÉS a szerver-oldali ellenőrzések
 | átmennek: szigorú TemplateCompliance (lábléc + VALÓDI postai cím), hitelesített feladó-
 | domain, és van megerősített marketing-hozzájárulású címzett. A kontroller UGYANEZT a
 | listát kérdezi az indításnál — a gomb letiltása kényelem, a szerver a határ. A küldés
 | pillanatában pedig továbbra is a PreSendGate dönt.
 */

beforeEach(function () {
    FakeDriver::reset();

    $this->workspace = Workspace::factory()->create();
    $this->context = app(WorkspaceContext::class);
    $this->context->set($this->workspace);
    $this->user = StudioFixtures::user($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
    Domain::create(['name' => 'hitelesitetlen.hu', 'status' => 'pending']);

    $this->list = ContactList::create(['name' => 'Hírlevél']);
    $this->template = checklistTemplate('Rendes sablon', 'Nádor utca 1., 1051 Budapest');
});

function checklistTemplate(string $name, string $address): Template
{
    return Template::create([
        'name' => $name,
        'marketing' => true,
        'blocks' => [
            ['type' => 'section', 'children' => [['type' => 'column', 'children' => [
                ['type' => 'text', 'attrs' => ['content' => 'Szia!']],
            ]]]],
            ['type' => 'section', 'children' => [['type' => 'column', 'children' => [
                ['type' => 'footer', 'attrs' => ['company' => 'Kft', 'address' => $address, 'content' => 'x', 'unsubscribe_label' => 'Leiratkozás', 'preferences_label' => 'Beállítások']],
            ]]]],
        ],
    ]);
}

function checklistMember(string $email, bool $confirmed): Contact
{
    $recorder = app(ConsentRecorder::class);
    $consent = $recorder->request($email);

    if ($confirmed) {
        $recorder->confirm($consent);
    }

    $contact = Contact::create(['email' => $email, 'status' => $confirmed ? 'subscribed' : 'pending']);
    $contact->lists()->attach(test()->list, ['status' => 'subscribed', 'subscribed_at' => now()]);

    return $contact;
}

function readyCampaign(array $extra = []): Campaign
{
    $mode = $extra['send_mode'] ?? 'now';
    unset($extra['send_mode']);

    $campaign = Campaign::create(array_merge([
        'name' => 'Őszi hírlevél',
        'subject' => 'Őszi újdonságok',
        'preheader' => 'Friss kávé',
        'from_email' => 'hirlevel@pelda.hu',
        'from_name' => 'Pelda',
        'template_id' => test()->template->getKey(),
        'target_lists' => [(string) test()->list->uuid],
        'timezone' => 'Europe/Budapest',
    ], $extra));

    $campaign->forceFill(['send_mode' => $mode])->save();

    return $campaign;
}

function asChecklistUser($test)
{
    return $test->actingAs($test->user)->withSession(['workspace_uuid' => $test->workspace->uuid]);
}

it('az új kampány minden kötelező szakasza HIÁNYOS, az A/B opcionális — nem küldhető', function () {
    $campaign = Campaign::create(['name' => 'Üres', 'subject' => '']);

    $checklist = app(CampaignChecklist::class)->evaluate($campaign);

    expect(collect($checklist['sections'])->pluck('done', 'key')->all())->toBe([
        'recipients' => false,
        'sender' => false,
        'subject' => false,
        'content' => false,
        'schedule' => false,
        'ab' => true,
    ])->and($checklist['ready'])->toBeFalse();
});

it('a teljes, megfelelő kampány KÉSZ — és a szerver-ellenőrzések zöldek', function () {
    checklistMember('ok@pelda.hu', true);
    checklistMember('fugg@pelda.hu', false);

    $checklist = app(CampaignChecklist::class)->evaluate(readyCampaign());

    expect($checklist['ready'])->toBeTrue()
        ->and(collect($checklist['checks'])->pluck('ok', 'key')->all())->toMatchArray([
            'domain' => true,
            'compliance' => true,
            'consent' => true,
        ])
        // A NEM megerősítettek számát is mondja: őket a 3. kapu el fogja utasítani.
        ->and(collect($checklist['checks'])->firstWhere('key', 'consent'))->toMatchArray(['confirmed' => 1, 'total' => 2]);
});

it('HELYŐRZŐ postai cím: a tartalom hiányos, és az indítás a SZERVEREN is elutasul', function () {
    checklistMember('ok@pelda.hu', true);

    $placeholder = checklistTemplate('Helyőrző', (string) __('editor.defaults.footerAddress'));
    $campaign = readyCampaign(['template_id' => $placeholder->getKey()]);

    $checklist = app(CampaignChecklist::class)->evaluate($campaign);

    expect(collect($checklist['sections'])->firstWhere('key', 'content')['done'])->toBeFalse()
        ->and(collect($checklist['checks'])->firstWhere('key', 'compliance')['missing'])->toContain('postal_address')
        ->and($checklist['ready'])->toBeFalse();

    asChecklistUser($this)->from('/campaigns/'.$campaign->uuid)
        ->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($campaign->fresh()->status)->toBe('draft')
        ->and(Message::query()->count())->toBe(0);
});

it('a LÁBLÉC nélküli (v1.1 előtti) sablon is hiányos a checklistben — szigorú mód', function () {
    checklistMember('ok@pelda.hu', true);

    $old = Template::create(['name' => 'Régi', 'blocks' => [['type' => 'section', 'children' => [['type' => 'column', 'children' => [
        ['type' => 'text', 'attrs' => ['content' => 'x']],
        ['type' => 'unsubscribe', 'attrs' => []],
    ]]]]]]);

    $checklist = app(CampaignChecklist::class)->evaluate(readyCampaign(['template_id' => $old->getKey()]));

    expect(collect($checklist['checks'])->firstWhere('key', 'compliance')['missing'])->toBe(['postal_address']);
});

it('HITELESÍTETLEN feladó-domain: a feladó hiányos, az indítás elutasul', function () {
    checklistMember('ok@pelda.hu', true);

    $campaign = readyCampaign(['from_email' => 'hirlevel@hitelesitetlen.hu']);

    $checklist = app(CampaignChecklist::class)->evaluate($campaign);

    expect(collect($checklist['sections'])->firstWhere('key', 'sender')['done'])->toBeFalse()
        ->and(collect($checklist['checks'])->firstWhere('key', 'domain')['ok'])->toBeFalse();

    asChecklistUser($this)->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'start'])
        ->assertSessionHas('error');

    expect($campaign->fresh()->status)->toBe('draft');
});

it('MEGERŐSÍTETT hozzájárulás nélküli közönség: a consent-ellenőrzés bukik', function () {
    checklistMember('fugg@pelda.hu', false);

    $checklist = app(CampaignChecklist::class)->evaluate(readyCampaign());

    expect(collect($checklist['checks'])->firstWhere('key', 'consent'))->toMatchArray(['ok' => false, 'confirmed' => 0, 'total' => 1])
        ->and($checklist['ready'])->toBeFalse();
});

it('KÜLDÉS MOST: a címzettek materializálódnak, és a PreSendGate dönt — csak a megerősített kap levelet', function () {
    checklistMember('ok@pelda.hu', true);
    checklistMember('fugg@pelda.hu', false);

    $campaign = readyCampaign();

    asChecklistUser($this)->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])
        ->assertSessionHas('success');

    $recipients = $campaign->fresh()->recipients()->get()->keyBy('email');

    expect($campaign->fresh()->status)->toBeIn(['sending', 'sent'])
        ->and($recipients['ok@pelda.hu']->status)->toBe('sent')
        ->and($recipients['fugg@pelda.hu']->status)->toBe('skipped')
        ->and($recipients['fugg@pelda.hu']->skip_reason)->toBe('NO_CONSENT');

    // A kampány oldal a kimenetet OKKAL mutatja (ez az e2e mérőpontja is).
    asChecklistUser($this)->get('/campaigns/'.$campaign->uuid)
        ->assertInertia(fn ($page) => $page
            ->where('outcomes', fn ($outcomes) => collect($outcomes)->contains(fn ($row) => $row['status'] === 'skipped' && $row['reason'] === 'NO_CONSENT' && $row['count'] === 1)));
});

it('KÉSŐBB: a workspace-időzónában megadott időpontra ütemez, UTC-ben tárol', function () {
    checklistMember('ok@pelda.hu', true);

    $campaign = readyCampaign(['send_mode' => 'later']);
    $local = now('Europe/Budapest')->addDays(2)->setTime(9, 0)->format('Y-m-d H:i');

    asChecklistUser($this)->put('/campaigns/'.$campaign->uuid, [
        'name' => $campaign->name,
        'subject' => $campaign->subject,
        'fromEmail' => 'hirlevel@pelda.hu',
        'templateUuid' => (string) $this->template->uuid,
        'targetLists' => [(string) $this->list->uuid],
        'sendMode' => 'later',
        'scheduledLocal' => $local,
        'timezone' => 'Europe/Budapest',
    ])->assertSessionHasNoErrors();

    expect(collect(app(CampaignChecklist::class)->evaluate($campaign->fresh())['sections'])->firstWhere('key', 'schedule')['done'])->toBeTrue();

    asChecklistUser($this)->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])
        ->assertSessionHas('success');

    $fresh = $campaign->fresh();

    expect($fresh->status)->toBe('scheduled')
        ->and($fresh->scheduledLocal()->format('Y-m-d H:i'))->toBe($local)
        ->and($fresh->recipients()->count())->toBe(0);
});

it('a MÚLTBELI időpont nem teszi késszé az ütemezést', function () {
    $campaign = readyCampaign(['send_mode' => 'later']);
    $campaign->forceFill(['scheduled_local' => '2020-01-01 09:00'])->save();

    expect(collect(app(CampaignChecklist::class)->evaluate($campaign)['sections'])->firstWhere('key', 'schedule')['done'])->toBeFalse();
});

it('A/B: tárgy-, tartalom- és FELADÓ-variáns; a variáns feladója is hitelesített kell legyen, és ki is megy vele', function () {
    checklistMember('a@pelda.hu', true);
    checklistMember('b@pelda.hu', true);

    $campaign = readyCampaign();

    asChecklistUser($this)->post('/campaigns/'.$campaign->uuid.'/variants', ['label' => 'A', 'subject' => 'Tárgy A', 'weight' => 50])
        ->assertSessionHasNoErrors();
    asChecklistUser($this)->post('/campaigns/'.$campaign->uuid.'/variants', ['label' => 'B', 'fromEmail' => 'ajanlat@hitelesitetlen.hu', 'weight' => 50])
        ->assertSessionHasNoErrors();

    $checklist = app(CampaignChecklist::class)->evaluate($campaign->fresh());

    expect(collect($checklist['checks'])->firstWhere('key', 'domain')['ok'])->toBeFalse();

    CampaignVariant::query()->where('label', 'B')->update(['from_email' => 'ajanlat@pelda.hu']);

    asChecklistUser($this)->put('/campaigns/'.$campaign->uuid, [
        'name' => $campaign->name,
        'subject' => $campaign->subject,
        'fromEmail' => 'hirlevel@pelda.hu',
        'templateUuid' => (string) $this->template->uuid,
        'targetLists' => [(string) $this->list->uuid],
        'sendMode' => 'now',
        'winnerCriterion' => 'click_rate',
        'winnerAfterMinutes' => 120,
    ])->assertSessionHasNoErrors();

    expect(app(CampaignChecklist::class)->evaluate($campaign->fresh())['ready'])->toBeTrue();

    asChecklistUser($this)->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])->assertSessionHas('success');

    expect(Message::query()->pluck('from_email')->sort()->values()->all())->toBe(['ajanlat@pelda.hu', 'hirlevel@pelda.hu'])
        ->and($campaign->fresh()->winner_criterion)->toBe('click_rate');
});

it('a variáns legalább egy dimenzióban eltér, és IDEGEN sablon nem köthető hozzá', function () {
    $campaign = readyCampaign();

    asChecklistUser($this)->post('/campaigns/'.$campaign->uuid.'/variants', ['label' => 'A', 'weight' => 50])
        ->assertSessionHasErrors();

    $foreign = $this->context->runFor(Workspace::factory()->create(), fn () => checklistTemplate('Idegen', 'Cím 1'));

    asChecklistUser($this)->post('/campaigns/'.$campaign->uuid.'/variants', ['label' => 'A', 'templateUuid' => (string) $foreign->uuid, 'weight' => 50])
        ->assertNotFound();
});

it('a SZERZŐ (author) szerkeszthet, de nem küldhet', function () {
    $author = StudioFixtures::user($this->workspace, 'author');
    checklistMember('ok@pelda.hu', true);
    $campaign = readyCampaign();

    $this->actingAs($author)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])
        ->assertForbidden();
});

it('a checklist-oldal a hitelesített domaineket és a merge-tageket adja a választókhoz', function () {
    $campaign = readyCampaign();

    asChecklistUser($this)->get('/campaigns/'.$campaign->uuid)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Campaign/Edit')
            ->where('domains', ['pelda.hu'])
            ->has('mergeTags')
            ->where('checklist.ready', false)
            ->where('defaultTimezone', fn ($tz) => is_string($tz) && $tz !== ''));
});

it('a TÁRGY merge-tagje címzettenként renderelődik, és a kampány PREHEADERE kerül a törzsbe', function () {
    $contact = checklistMember('anna@pelda.hu', true);
    $contact->forceFill(['first_name' => 'Anna'])->save();

    $campaign = readyCampaign(['subject' => 'Szia {{ contact.first_name || Vendég }}!', 'preheader' => 'Kampány-preheader szöveg']);

    asChecklistUser($this)->post('/campaigns/'.$campaign->uuid.'/transition', ['action' => 'send'])->assertSessionHas('success');

    $message = Message::query()->firstOrFail();

    expect($message->subject)->toBe('Szia Anna!')
        ->and((string) ($message->options['body_html'] ?? ''))->toContain('Kampány-preheader szöveg');
});
