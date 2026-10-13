<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Enums\Role;
use App\Modules\Journey\Actions\FireJourneyEvent;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Graph\JourneyGraph;
use App\Modules\Journey\Graph\JourneyGraphValidator;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Models\JourneyRunStep;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Journey\Models\JourneyVersion;
use App\Modules\Journey\Services\JourneyEngine;
use App\Modules\Journey\Services\JourneyGraphInvalid;
use App\Modules\Journey\Services\JourneyNodeStats;
use App\Modules\Journey\Services\JourneyPublisher;
use App\Modules\Journey\Services\JourneyTrigger;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Services\TemplateUsage;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | v1.1 · 6. modul — JOURNEY VÁSZON: gráf, validálás, verziók, motor, statisztika,
 | teszt-futtatás, jogosultság.
 |
 | A TÉT: az aktív journey módosítása ÚJ verziót hoz, és a bent lévők a RÉGIN haladnak
 | tovább; a teszt-futtatás NEM küld; a webhook csomópont a meglévő védett kézbesítésen megy.
 */

beforeEach(function () {
    FakeDriver::reset();

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->template = canvasTemplate('Üdvözlő levél');
    $this->template2 = canvasTemplate('Második levél');
});

function canvasTemplate(string $name): Template
{
    return Template::create([
        'name' => $name,
        'subject' => $name,
        'blocks' => [[
            'type' => 'section',
            'children' => [['type' => 'column', 'children' => [
                ['type' => 'text', 'attrs' => ['content' => 'Szia!']],
                ['type' => 'unsubscribe', 'attrs' => []],
            ]]],
        ]],
    ]);
}

function canvasContact(string $email = 'anna@pelda.hu', bool $confirmed = true): Contact
{
    if ($confirmed) {
        $recorder = app(ConsentRecorder::class);
        $recorder->confirm($recorder->request($email));
    }

    return Contact::create(['email' => $email, 'status' => 'subscribed', 'subscribed_at' => now()]);
}

/** @param array<string, mixed> $config */
function cnode(string $id, string $type, array $config = [], float $x = 0): array
{
    return ['id' => $id, 'type' => $type, 'position' => ['x' => $x, 'y' => 0], 'label' => '', 'config' => $config];
}

function cedge(string $source, string $target, string $handle = 'out'): array
{
    return ['id' => "e-{$source}-{$handle}-{$target}", 'source' => $source, 'sourceHandle' => $handle, 'target' => $target];
}

/**
 * @param  list<array<string, mixed>>  $nodes
 * @param  list<array<string, mixed>>  $edges
 */
function canvasJourney(array $nodes, array $edges, string $name = 'Vászon'): Journey
{
    return Journey::create([
        'name' => $name.' '.bin2hex(random_bytes(3)),
        'trigger_type' => 'api_event',
        'graph' => ['nodes' => $nodes, 'edges' => $edges],
    ]);
}

function canvasUser(Workspace $workspace, string $role = 'owner'): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'c-'.uniqid(),
        'name' => 'Vászon',
        'email' => 'c-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);

    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

    return $user;
}

/** trigger(api_event) → send → wait 2 nap → exit */
function welcomeGraph(string $template): array
{
    return [
        [
            cnode('t', 'api_event', ['event' => 'signup']),
            cnode('send', 'send_email', ['template' => $template, 'from_email' => 'no-reply@pelda.hu'], 100),
            cnode('wait', 'wait', ['minutes' => 2 * 24 * 60], 200),
            cnode('end', 'exit', [], 300),
        ],
        [cedge('t', 'send'), cedge('send', 'wait'), cedge('wait', 'end')],
    ];
}

function issueCodes(array $issues, string $severity = 'error'): array
{
    return array_values(array_map(
        fn (array $issue) => $issue['code'].(isset($issue['node']) ? '@'.$issue['node'] : ''),
        array_filter($issues, fn (array $issue) => $issue['severity'] === $severity),
    ));
}

/*
 | VALIDÁLÁS
 */

it('a hiányzó trigger, az árva csomópont és a kötelező konfiguráció HIBA', function () {
    $graph = JourneyGraph::fromArray(['nodes' => [
        cnode('send', 'send_email', []),
        cnode('lonely', 'exit'),
    ], 'edges' => []]);

    $codes = issueCodes(app(JourneyGraphValidator::class)->validate($graph));

    expect($codes)->toContain('missing_trigger')
        ->and($codes)->toContain('config_required@send');

    $graph = JourneyGraph::fromArray(['nodes' => [
        cnode('t', 'api_event', ['event' => 'x']),
        cnode('a', 'exit'),
        cnode('orphan', 'exit'),
    ], 'edges' => [cedge('t', 'a')]]);

    expect(issueCodes(app(JourneyGraphValidator::class)->validate($graph)))->toBe(['orphan_node@orphan']);
});

it('a VÁRAKOZÁS NÉLKÜLI hurok hiba — a wait-es hurok legitim', function () {
    $tag = ContactTag::create(['name' => 'vip']);

    $loop = JourneyGraph::fromArray(['nodes' => [
        cnode('t', 'api_event', ['event' => 'x']),
        cnode('a', 'update_contact', ['operation' => 'add_tag', 'tag' => (string) $tag->uuid]),
        cnode('b', 'update_contact', ['operation' => 'remove_tag', 'tag' => (string) $tag->uuid]),
    ], 'edges' => [cedge('t', 'a'), cedge('a', 'b'), cedge('b', 'a')]]);

    expect(issueCodes(app(JourneyGraphValidator::class)->validate($loop)))->toContain('loop_without_wait@a');

    $weekly = JourneyGraph::fromArray(['nodes' => [
        cnode('t', 'api_event', ['event' => 'x']),
        cnode('a', 'send_email', ['template' => (string) $this->template->uuid]),
        cnode('w', 'wait', ['minutes' => 60 * 24 * 7]),
    ], 'edges' => [cedge('t', 'a'), cedge('a', 'w'), cedge('w', 'a')]]);

    expect(issueCodes(app(JourneyGraphValidator::class)->validate($weekly)))->toBe([]);
});

it('az IDEGEN tenant sablonja „nem létezik", az A/B arány 100 kell legyen, és egy trigger lehet', function () {
    $idegen = Workspace::factory()->create();
    $idegenSablon = app(WorkspaceContext::class)->runFor($idegen, fn () => canvasTemplate('Idegen'));

    $graph = JourneyGraph::fromArray(['nodes' => [
        cnode('t', 'api_event', ['event' => 'x']),
        cnode('t2', 'form_signup'),
        cnode('send', 'send_email', ['template' => (string) $idegenSablon->uuid]),
        cnode('ab', 'ab_split', ['variants' => [['key' => 'a', 'weight' => 60], ['key' => 'b', 'weight' => 30]]]),
    ], 'edges' => [cedge('t', 'send'), cedge('send', 'ab'), cedge('t2', 'ab')]]);

    $codes = issueCodes(app(JourneyGraphValidator::class)->validate($graph));

    expect($codes)->toContain('reference_missing@send')
        ->and($codes)->toContain('ab_weights_invalid@ab')
        ->and($codes)->toContain('multiple_triggers@t2');
});

it('a gráf szerkezete korlátos — túl sok csomópont, rossz azonosító elutasítva', function () {
    $nodes = array_map(fn (int $i) => cnode('n'.$i, 'exit'), range(1, JourneyGraph::MAX_NODES + 1));

    expect(fn () => JourneyGraph::fromArray(['nodes' => $nodes, 'edges' => []]))->toThrow(InvalidArgumentException::class);
    expect(fn () => JourneyGraph::fromArray(['nodes' => [cnode('<script>', 'exit')], 'edges' => []]))->toThrow(InvalidArgumentException::class);
});

/*
 | PUBLIKÁLÁS ÉS VERZIÓK
 */

it('a publikálás verziót és lépéseket hoz létre, beállítja a triggert, és auditált', function () {
    [$nodes, $edges] = welcomeGraph((string) $this->template->uuid);
    $journey = canvasJourney($nodes, $edges);

    $version = app(JourneyPublisher::class)->publish($journey);
    $journey->refresh();

    expect($version->number)->toBe(1)
        ->and($journey->status)->toBe('active')
        ->and($journey->trigger_type)->toBe('api_event')
        ->and($journey->trigger_config)->toBe(['event' => 'signup'])
        ->and((int) $journey->current_version_id)->toBe((int) $version->getKey())
        ->and(JourneyStep::query()->where('journey_version_id', $version->getKey())->count())->toBe(3)
        ->and((int) $journey->first_step_id)->toBe((int) JourneyStep::query()->where('node_key', 'send')->value('id'))
        ->and(AuditLog::query()->where('action', 'journey.published')->exists())->toBeTrue();

    // VÁLTOZATLAN gráf újrapublikálása nem hoz új verziót.
    app(JourneyPublisher::class)->publish($journey);
    expect(JourneyVersion::query()->count())->toBe(1);
});

it('a HIBÁS gráf nem publikálható', function () {
    $journey = canvasJourney([cnode('send', 'send_email', [])], []);

    expect(fn () => app(JourneyPublisher::class)->publish($journey))->toThrow(JourneyGraphInvalid::class);
    expect($journey->fresh()->status)->toBe('draft');
});

it('a publikált verzió MEGVÁLTOZTATHATATLAN, és a verzió-hivatkozás nem léphet át journey-határt', function () {
    [$nodes, $edges] = welcomeGraph((string) $this->template->uuid);
    $a = canvasJourney($nodes, $edges, 'A');
    $b = canvasJourney($nodes, $edges, 'B');

    $versionA = app(JourneyPublisher::class)->publish($a);
    app(JourneyPublisher::class)->publish($b);

    expect(fn () => $versionA->forceFill(['graph' => []])->save())->toThrow(LogicException::class);

    $stepB = JourneyStep::query()->where('journey_id', $b->getKey())->firstOrFail();

    expect(fn () => $stepB->forceFill(['journey_version_id' => $versionA->getKey()])->save())
        ->toThrow(QueryException::class);
});

it('AKTÍV journey módosítása ÚJ verzió — a bent lévő a RÉGIN halad tovább', function () {
    canvasContact('anna@pelda.hu');
    canvasContact('bela@pelda.hu');

    // v1: wait 1 nap → send (template 1)
    $journey = canvasJourney([
        cnode('t', 'api_event', ['event' => 'signup']),
        cnode('wait', 'wait', ['minutes' => 24 * 60], 100),
        cnode('send', 'send_email', ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu'], 200),
    ], [cedge('t', 'wait'), cedge('wait', 'send')]);

    $v1 = app(JourneyPublisher::class)->publish($journey);

    $anna = app(JourneyTrigger::class)->fire(TriggerType::ApiEvent, 'anna@pelda.hu', ['event' => 'signup'])[0];
    app(JourneyEngine::class)->tick($anna);

    expect($anna->fresh()->status)->toBe('waiting')
        ->and((int) $anna->fresh()->journey_version_id)->toBe((int) $v1->getKey());

    // v2: ugyanaz, de a MÁSIK sablonnal.
    $graph = $journey->fresh()->graph;
    $graph['nodes'][2]['config']['template'] = (string) $this->template2->uuid;
    $journey->forceFill(['graph' => $graph])->save();

    $v2 = app(JourneyPublisher::class)->publish($journey->fresh());

    expect($v2->number)->toBe(2);

    $bela = app(JourneyTrigger::class)->fire(TriggerType::ApiEvent, 'bela@pelda.hu', ['event' => 'signup'])[0];
    expect((int) $bela->journey_version_id)->toBe((int) $v2->getKey());

    app(JourneyEngine::class)->tick($bela);
    $this->travel(1)->days();
    $this->travel(1)->minute();

    app(JourneyEngine::class)->tick($anna->fresh());
    app(JourneyEngine::class)->tick($bela->fresh());

    $subjects = Message::query()->get()->mapWithKeys(fn (Message $message) => [(string) $message->recipients[0] => (string) $message->subject]);

    // Anna a RÉGI verzió levelét kapta, Béla az újat.
    expect($subjects['anna@pelda.hu'])->toBe('Üdvözlő levél')
        ->and($subjects['bela@pelda.hu'])->toBe('Második levél')
        ->and($anna->fresh()->status)->toBe('completed');
});

/*
 | MOTOR — a vászon csomópontjai
 */

it('a wait csomóponton VÁR a futás, és a továbblépés ELŐRE ÖSSZESÍTETT számlálóba kerül', function () {
    canvasContact();
    [$nodes, $edges] = welcomeGraph((string) $this->template->uuid);
    $journey = canvasJourney($nodes, $edges);
    app(JourneyPublisher::class)->publish($journey);

    $run = app(JourneyTrigger::class)->fire(TriggerType::ApiEvent, 'anna@pelda.hu', ['event' => 'signup'])[0];
    app(JourneyEngine::class)->tick($run);

    $step = fn (string $key) => JourneyStep::query()->where('journey_id', $journey->getKey())->where('node_key', $key)->firstOrFail();

    // A futás a WAIT csomóponton áll (nem a következőn), a küldés továbblépett.
    expect((int) $run->fresh()->current_step_id)->toBe((int) $step('wait')->getKey())
        ->and($step('send')->executed_count)->toBe(1)
        ->and($step('wait')->executed_count)->toBe(0);

    // A várakozás alatti tickek NEM lépnek, és nem égetik a lépés-korlátot.
    app(JourneyEngine::class)->tick($run->fresh());
    expect((int) $run->fresh()->steps_taken)->toBe(1);

    $this->travel(2)->days();
    $this->travel(1)->minute();
    app(JourneyEngine::class)->tick($run->fresh());

    expect($run->fresh()->status)->toBe('exited')
        ->and($run->fresh()->exit_reason)->toBe('step_exit')
        ->and($step('wait')->executed_count)->toBe(1)
        ->and($step('end')->executed_count)->toBe(1)
        ->and(Message::query()->count())->toBe(1);
});

it('az ESEMÉNYRE váró wait: a nyitás az „out", a lejárat a „timeout" ágra visz', function () {
    canvasContact('anna@pelda.hu');
    canvasContact('bela@pelda.hu');
    $tagOpened = ContactTag::create(['name' => 'megnyitotta']);
    $tagTimeout = ContactTag::create(['name' => 'nem nyitotta']);

    $journey = canvasJourney([
        cnode('t', 'api_event', ['event' => 'x']),
        cnode('w', 'wait', ['mode' => 'event', 'event' => 'opened', 'timeout_minutes' => 120], 100),
        cnode('yes', 'update_contact', ['operation' => 'add_tag', 'tag' => (string) $tagOpened->uuid], 200),
        cnode('no', 'update_contact', ['operation' => 'add_tag', 'tag' => (string) $tagTimeout->uuid], 200),
    ], [cedge('t', 'w'), cedge('w', 'yes', 'out'), cedge('w', 'no', 'timeout')]);
    app(JourneyPublisher::class)->publish($journey);

    $anna = app(JourneyTrigger::class)->fire(TriggerType::ApiEvent, 'anna@pelda.hu', ['event' => 'x'])[0];
    $bela = app(JourneyTrigger::class)->fire(TriggerType::ApiEvent, 'bela@pelda.hu', ['event' => 'x'])[0];
    app(JourneyEngine::class)->tick($anna);
    app(JourneyEngine::class)->tick($bela);

    $this->travel(5)->minutes();
    MessageEvent::create(['message_uuid' => null, 'type' => 'opened', 'recipient' => 'anna@pelda.hu', 'channel' => 'journey', 'tags' => [], 'variables' => [], 'payload' => []]);

    $this->travel(20)->minutes();
    app(JourneyEngine::class)->tick($anna->fresh());

    expect($anna->fresh()->status)->toBe('completed')
        ->and(Contact::query()->where('email', 'anna@pelda.hu')->firstOrFail()->tags()->pluck('name')->all())->toBe(['megnyitotta']);

    $this->travel(2)->hours();
    app(JourneyEngine::class)->tick($bela->fresh());

    expect(Contact::query()->where('email', 'bela@pelda.hu')->firstOrFail()->tags()->pluck('name')->all())->toBe(['nem nyitotta'])
        ->and(JourneyRunStep::query()->where('journey_run_id', $bela->getKey())->whereNotNull('outcome')->pluck('outcome')->all())->toContain('timeout');
});

it('a TÖBBÁGÚ elágazás az első igaz ágra, az A/B determinisztikusan választ', function () {
    $contact = canvasContact();
    $vip = ContactTag::create(['name' => 'vip']);
    $contact->tags()->attach($vip->getKey());
    $list = ContactList::create(['name' => 'VIP lista']);

    $journey = canvasJourney([
        cnode('t', 'api_event', ['event' => 'x']),
        cnode('s', 'split', ['branches' => [
            ['key' => 'buyer', 'condition' => ['type' => 'field_equals', 'key' => 'first_name', 'value' => 'Nincs']],
            ['key' => 'vip', 'condition' => ['type' => 'has_tag', 'tag' => (string) $vip->uuid]],
        ]], 100),
        cnode('l', 'list_membership', ['operation' => 'add', 'list' => (string) $list->uuid], 200),
        cnode('ab', 'ab_split', ['variants' => [['key' => 'a', 'weight' => 50], ['key' => 'b', 'weight' => 50]]], 300),
        cnode('ea', 'exit', [], 400),
        cnode('eb', 'goal', [], 400),
    ], [cedge('t', 's'), cedge('s', 'l', 'vip'), cedge('l', 'ab'), cedge('ab', 'ea', 'a'), cedge('ab', 'eb', 'b')]);
    app(JourneyPublisher::class)->publish($journey);

    $run = app(JourneyTrigger::class)->fire(TriggerType::ApiEvent, 'anna@pelda.hu', ['event' => 'x'])[0];
    app(JourneyEngine::class)->tick($run);

    $outcomes = JourneyRunStep::query()->where('journey_run_id', $run->getKey())->orderBy('id')->pluck('outcome')->all();
    $expected = JourneyEngine::pickVariant([['key' => 'a', 'weight' => 50], ['key' => 'b', 'weight' => 50]], $run->uuid.'|'.JourneyStep::query()->where('node_key', 'ab')->value('uuid'));

    expect($outcomes[0])->toBe('vip')
        ->and($outcomes[2])->toBe($expected)
        ->and($contact->lists()->pluck('name')->all())->toBe(['VIP lista'])
        ->and($run->fresh()->exit_reason)->toBe($expected === 'a' ? 'step_exit' : 'goal_reached');
});

it('a WEBHOOK csomópont a meglévő, védett kézbesítésen megy — nem nyers HTTP-hívás', function () {
    Queue::fake();
    canvasContact();
    $endpoint = WebhookEndpoint::issue('CRM', 'https://93.184.216.34/hook', [])['endpoint'];

    $journey = canvasJourney([
        cnode('t', 'api_event', ['event' => 'x']),
        cnode('h', 'webhook', ['endpoint' => (string) $endpoint->uuid], 100),
    ], [cedge('t', 'h')]);
    app(JourneyPublisher::class)->publish($journey);

    $run = app(JourneyTrigger::class)->fire(TriggerType::ApiEvent, 'anna@pelda.hu', ['event' => 'x'])[0];
    app(JourneyEngine::class)->tick($run);

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->event_type)->toBe('journey.step')
        ->and($delivery->payload['journey']['node'])->toBe('h')
        ->and($run->fresh()->status)->toBe('completed');

    Queue::assertPushed(DeliverWebhook::class);

    // A KIKAPCSOLT végpont nem cél: a futás megnevezett okkal zárul.
    $endpoint->forceFill(['active' => false])->save();
    $second = canvasContact('bela@pelda.hu');
    $run2 = app(JourneyTrigger::class)->fire(TriggerType::ApiEvent, (string) $second->email, ['event' => 'x'])[0];
    app(JourneyEngine::class)->tick($run2);

    expect($run2->fresh()->exit_reason)->toBe('config_invalid');
});

it('a SZÜNETELTETETT journey TART — nem léptet ki, és a folytatás után megy tovább', function () {
    canvasContact();
    [$nodes, $edges] = welcomeGraph((string) $this->template->uuid);
    $journey = canvasJourney($nodes, $edges);
    app(JourneyPublisher::class)->publish($journey);

    $run = app(JourneyTrigger::class)->fire(TriggerType::ApiEvent, 'anna@pelda.hu', ['event' => 'signup'])[0];
    app(JourneyEngine::class)->tick($run);

    $journey->forceFill(['status' => 'paused'])->save();
    $this->travel(3)->days();
    app(JourneyEngine::class)->tick($run->fresh());

    expect($run->fresh()->status)->toBe('waiting');

    // Folytatás (változatlan gráf → nincs új verzió).
    app(JourneyPublisher::class)->publish($journey->fresh());
    app(JourneyEngine::class)->tick($run->fresh());

    expect(JourneyVersion::query()->count())->toBe(1)
        ->and($run->fresh()->status)->toBe('exited');
});

/*
 | JOGOSULTSÁG, AUDIT, TENANT
 */

it('a szerző szerkeszthet, de NEM publikálhat és nem szüneteltethet; a szerkesztő igen — auditálva', function () {
    [$nodes, $edges] = welcomeGraph((string) $this->template->uuid);
    $journey = canvasJourney($nodes, $edges);
    $author = canvasUser($this->workspace, Role::Author->value);
    $editor = canvasUser($this->workspace, Role::Editor->value);
    $session = ['workspace_uuid' => $this->workspace->uuid];

    $this->actingAs($author)->withSession($session)
        ->putJson('/journeys/'.$journey->uuid.'/graph', ['graph' => ['nodes' => $nodes, 'edges' => $edges]])
        ->assertOk()
        ->assertJsonPath('dirty', true);

    $this->actingAs($author)->withSession($session)->postJson('/journeys/'.$journey->uuid.'/publish')->assertForbidden();

    $this->actingAs($editor)->withSession($session)
        ->postJson('/journeys/'.$journey->uuid.'/publish')
        ->assertOk()
        ->assertJsonPath('version', 1);

    $this->actingAs($author)->withSession($session)->postJson('/journeys/'.$journey->uuid.'/pause')->assertForbidden();
    $this->actingAs($editor)->withSession($session)->postJson('/journeys/'.$journey->uuid.'/pause')->assertOk();

    expect($journey->fresh()->status)->toBe('paused')
        ->and(AuditLog::query()->where('action', 'journey.published')->value('user_id'))->toBe($editor->getKey())
        ->and(AuditLog::query()->where('action', 'journey.paused')->exists())->toBeTrue();
});

it('az AKTÍV vászon-journey sablonja publikálási kapu alatt van (D122 TemplateUsage)', function () {
    [$nodes, $edges] = welcomeGraph((string) $this->template->uuid);
    $journey = canvasJourney($nodes, $edges, 'Élő');

    expect(app(TemplateUsage::class)->blockers($this->template)['journeys'])->toBe([]);

    app(JourneyPublisher::class)->publish($journey);

    expect(app(TemplateUsage::class)->blockers($this->template)['journeys'])->toBe([(string) $journey->name]);

    $this->actingAs(canvasUser($this->workspace, Role::Author->value));

    expect(app(TemplateUsage::class)->writeDenial($this->template))->not->toBeNull();
});

/*
 | TRIGGEREK
 */

it('az új triggerek tüzelnek: lista-feliratkozás, kampány-interakció, évforduló', function () {
    canvasContact();
    $list = ContactList::create(['name' => 'Hírlevél']);

    $listJourney = canvasJourney([
        cnode('t', 'list_subscribe', ['list' => (string) $list->uuid]),
        cnode('e', 'exit', [], 100),
    ], [cedge('t', 'e')]);
    app(JourneyPublisher::class)->publish($listJourney);

    $contact = Contact::query()->where('email', 'anna@pelda.hu')->firstOrFail();

    expect(app(FireJourneyEvent::class)->listSubscribed($contact, $list))->toHaveCount(1);

    $clickJourney = canvasJourney([
        cnode('t', 'campaign_interaction', ['interaction' => 'click']),
        cnode('e', 'exit', [], 100),
    ], [cedge('t', 'e')]);
    app(JourneyPublisher::class)->publish($clickJourney);

    expect(app(FireJourneyEvent::class)->campaignInteraction('anna@pelda.hu', (string) Str::uuid(), 'open'))->toHaveCount(0)
        ->and(app(FireJourneyEvent::class)->campaignInteraction('anna@pelda.hu', (string) Str::uuid(), 'click'))->toHaveCount(1);

    $contact->setAttribute('attributes', ['birthday' => now()->subYears(30)->toDateString()]);
    $contact->save();
    ContactField::create(['key' => 'birthday', 'label' => 'Születésnap', 'type' => 'date']);

    $birthday = canvasJourney([
        cnode('t', 'date_anniversary', ['field' => 'birthday']),
        cnode('e', 'exit', [], 100),
    ], [cedge('t', 'e')]);
    app(JourneyPublisher::class)->publish($birthday);

    expect($birthday->fresh()->allow_reentry)->toBeTrue();

    app(WorkspaceContext::class)->forget();
    $this->artisan('journeys:detect-dates')->assertSuccessful();
    app(WorkspaceContext::class)->set($this->workspace);

    expect(JourneyRun::query()->where('journey_id', $birthday->getKey())->count())->toBe(1);
});

/*
 | STATISZTIKA ÉS TESZT-FUTTATÁS — valódi küldés NÉLKÜL
 */

it('a CSOMÓPONT-STATISZTIKA: belépett · vár · továbblépett — a vár a wait csomóponton', function () {
    canvasContact();
    [$nodes, $edges] = welcomeGraph((string) $this->template->uuid);
    $journey = canvasJourney($nodes, $edges);
    app(JourneyPublisher::class)->publish($journey);

    $run = app(JourneyTrigger::class)->fire(TriggerType::ApiEvent, 'anna@pelda.hu', ['event' => 'signup'])[0];
    app(JourneyEngine::class)->tick($run);

    $journey->refresh();
    $stats = app(JourneyNodeStats::class)->forJourney($journey, JourneyGraph::fromArray($journey->graph));

    expect($stats['available'])->toBeTrue()
        ->and($stats['nodes']['t'])->toBe(['entered' => 1, 'waiting' => 0, 'movedOn' => 1])
        ->and($stats['nodes']['send'])->toBe(['entered' => 1, 'waiting' => 0, 'movedOn' => 1])
        ->and($stats['nodes']['wait'])->toBe(['entered' => 1, 'waiting' => 1, 'movedOn' => 0]);

    // A várakozás alatti tickek NEM lépnek, és nem égetik a lépés-korlátot.
    app(JourneyEngine::class)->tick($run->fresh());
    expect((int) $run->fresh()->steps_taken)->toBe(1);

    $this->travel(2)->days();
    $this->travel(1)->minute();
    app(JourneyEngine::class)->tick($run->fresh());

    $stats = app(JourneyNodeStats::class)->forJourney($journey, JourneyGraph::fromArray($journey->graph));

    expect($run->fresh()->status)->toBe('exited')
        ->and($run->fresh()->exit_reason)->toBe('step_exit')
        ->and($stats['nodes']['wait'])->toBe(['entered' => 1, 'waiting' => 0, 'movedOn' => 1])
        ->and($stats['nodes']['end']['movedOn'])->toBe(1)
        ->and(Message::query()->count())->toBe(1);
});

it('a TESZT-FUTTATÁS nem küld, nem hoz létre futást, és a kapu-döntést csak olvassa', function () {
    canvasContact('anna@pelda.hu');
    canvasContact('bela@pelda.hu', confirmed: false);

    [$nodes, $edges] = welcomeGraph((string) $this->template->uuid);
    $journey = canvasJourney($nodes, $edges);
    $user = canvasUser($this->workspace, Role::Author->value);

    $response = $this->actingAs($user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->postJson('/journeys/'.$journey->uuid.'/test-run', ['email' => 'anna@pelda.hu'])
        ->assertOk()
        ->json();

    expect(array_column($response['steps'], 'outcome'))->toBe(['entered', 'would_send', 'waited', 'exit'])
        ->and($response['realSends'])->toBe(0)
        ->and($response['simulatedMinutes'])->toBe(2 * 24 * 60);

    $blocked = $this->actingAs($user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->postJson('/journeys/'.$journey->uuid.'/test-run', ['email' => 'bela@pelda.hu'])
        ->assertOk()
        ->json();

    expect($blocked['steps'][1]['outcome'])->toBe('blocked')
        ->and($blocked['steps'][1]['detail'])->toBe('NO_CONSENT');

    // SEMMI nem íródott: se üzenet, se futás, se driver-hívás.
    expect(Message::query()->count())->toBe(0)
        ->and(JourneyRun::query()->count())->toBe(0)
        ->and(FakeDriver::$sent)->toBe([]);
});
