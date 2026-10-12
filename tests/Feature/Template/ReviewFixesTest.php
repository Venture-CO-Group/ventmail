<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaign\Actions\StartCampaign;
use App\Modules\Campaign\Jobs\SendCampaignChunk;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Deliverability\Contracts\BlocklistClient;
use App\Modules\Deliverability\Services\SpamScorer;
use App\Modules\Deliverability\Testing\FakeBlocklistClient;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Enums\Role;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Journey\Services\JourneyEngine;
use App\Modules\Journey\Services\JourneyTrigger;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\StoredTemplates\Services\ConditionalContent;
use App\Modules\Template\Http\Controllers\SavedBlockController;
use App\Modules\Template\Models\SavedBlock;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Models\TemplateAsset;
use App\Modules\Template\Services\BlockTreeValidator;
use App\Modules\Template\Services\HtmlSanitizer;
use App\Modules\Template\Services\MergeVariables;
use App\Modules\Template\Services\MjmlCompiler;
use App\Modules\Template\Services\MjmlWriter;
use App\Modules\Template\Services\PlainTextWriter;
use App\Modules\Template\Services\TemplateCompliance;
use App\Modules\Template\Services\WebVersionTokens;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 | v1.1 · 4. modul — a review és a security review javításai (B1, B2, SB1, D52, S1–S11,
 | SS1–SS5). Minden teszt egy MÉRT hibát zár le.
 */

beforeEach(function () {
    $this->withoutVite();
    config(['deliverability.spam_score.domain_blocklist_zone' => '']);
    FakeDriver::reset();

    $this->workspace = Workspace::factory()->create(['name' => 'Nádor Kávéház']);
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->user = User::factory()->create(['name' => 'Szabó Bence', 'email' => 'bence@pelda.hu']);
    $this->workspace->members()->create(['user_id' => $this->user->id, 'roles' => [Role::Owner->value]]);
    $this->actingAs($this->user)->withSession(['workspace_uuid' => $this->workspace->uuid]);
});

/** @param list<array<string, mixed>> $children */
function rvTree(array $children, string $address = '1000 Budapest, Valódi utca 7.'): array
{
    return [
        ['type' => 'section', 'children' => [['type' => 'column', 'children' => $children]]],
        ['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'footer', 'attrs' => ['address' => $address]],
        ]]]],
    ];
}

function rvSubscriber(string $email, ContactList $list, array $attrs = []): Contact
{
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request($email));

    $contact = Contact::create(['email' => $email, 'status' => 'subscribed', 'subscribed_at' => now()] + $attrs);
    $contact->lists()->attach($list, ['status' => 'subscribed', 'subscribed_at' => now()]);

    return $contact;
}

function rvBody(string $recipient): string
{
    foreach (FakeDriver::$sent as $sent) {
        if (isset($sent['bodies'][$recipient])) {
            return (string) $sent['bodies'][$recipient]['html'];
        }
    }

    return '';
}

/*
 | SB1 · A FELTÉTELES TARTALOM lineáris elemzővel, fail-closed
 */

it('SB1: 1 MB feltételes tartalom is kiértékelődik — nincs üres törzs', function () {
    $open = ConditionalContent::open(['kind' => 'field', 'field' => 'contact.first_name', 'op' => 'is_set']);
    $html = '<p>előtte</p>'.$open.str_repeat('x', 1_000_000).ConditionalContent::END.'<p>lábléc</p>';

    $shown = ConditionalContent::apply($html, ['contact' => ['first_name' => 'Anna']]);
    $hidden = ConditionalContent::apply($html, []);

    expect(strlen($shown))->toBe(strlen('<p>előtte</p>') + 1_000_000 + strlen('<p>lábléc</p>'))
        ->and($hidden)->toBe('<p>előtte</p><p>lábléc</p>');
});

it('SB1: beágyazott, le nem zárt és hamis jelölő — fail-closed', function () {
    $yes = ConditionalContent::open(['kind' => 'field', 'field' => 'contact.first_name', 'op' => 'is_set']);
    $no = ConditionalContent::open(['kind' => 'field', 'field' => 'contact.first_name', 'op' => 'is_not_set']);
    $vars = ['contact' => ['first_name' => 'A']];

    expect(ConditionalContent::apply('a'.$yes.'b'.$no.'X'.ConditionalContent::END.'c'.ConditionalContent::END.'d', $vars))->toBe('abcd')
        // LE NEM ZÁRT nyitó: a maradék elrejtve.
        ->and(ConditionalContent::apply('a'.$yes.'b', $vars))->toBe('ab')
        ->and(ConditionalContent::apply('a<!--vm:if:!!!-->titok'.ConditionalContent::END.'b', $vars))->toBe('ab')
        // PÁRATLAN záró: eltűnik, a tartalom marad.
        ->and(ConditionalContent::apply('a'.ConditionalContent::END.'b', $vars))->toBe('ab')
        // A STRIP mindent elrejt.
        ->and(ConditionalContent::strip('a'.$yes.'VIP'.ConditionalContent::END.'b'))->toBe('ab');
});

it('SB1: üres renderelt HTML-törzs SOHA nem megy ki (dispatcher-őr)', function () {
    $condition = ConditionalContent::open(['kind' => 'segment', 'segment' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', 'op' => 'in']);

    expect(fn () => app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'hello@pelda.hu',
        'to' => ['bence@pelda.hu'],
        'subject' => 'Csak VIP',
        'html' => $condition.'<p>VIP</p>'.ConditionalContent::END,
        'options' => ['studio_origin' => 'template_test'],
        'idempotency_key' => 'ures-1',
    ]))->toThrow(RuntimeException::class, 'üres');

    expect(FakeDriver::$sent)->toBe([])
        ->and(Message::query()->where('idempotency_key', 'ures-1')->value('status'))->toBe('deferred');
});

it('a feltételes rész CSAK Studio-eredetű törzsön értékelődik ki — máshol eltűnik', function () {
    $condition = ConditionalContent::open(['kind' => 'field', 'field' => 'contact.first_name', 'op' => 'is_set']);
    $html = '<p>mindenkinek</p>'.$condition.'<p>VIP</p>'.ConditionalContent::END;

    app(SendMessage::class)([
        'channel' => 'transactional', 'from_email' => 'hello@pelda.hu', 'to' => ['api@pelda.hu'], 'subject' => 'API',
        'html' => $html, 'variables' => ['contact' => ['first_name' => 'Anna']], 'idempotency_key' => 'api-1',
    ]);

    expect(rvBody('api@pelda.hu'))->toContain('mindenkinek')->not->toContain('VIP')->not->toContain('vm:');
});

/*
 | SS3 · A SANITIZER minden kommentet eldob
 */

it('SS3: [if mso] blokk, <!--> mXSS és hamisított vm: jelölő nem jut át a sanitizeren', function () {
    $sanitizer = app(HtmlSanitizer::class);

    foreach (['block', 'rich', 'inline'] as $profile) {
        expect($sanitizer->{$profile}('<!--[if mso]><img src="http://t.example/p.gif"><![endif]-->ok'))->toBe('ok')
            ->and($sanitizer->{$profile}('<!--><img src=x onerror=alert(2)>-->'))->not->toContain('onerror')->not->toContain('<!--')
            ->and($sanitizer->{$profile}('x<!--vm:endif-->y'))->toBe('xy');
    }
});

it('SS3: a szövegbe írt jelölő a plaintextben sem lesz valódi jelölő', function () {
    $text = app(PlainTextWriter::class)->write(rvTree([
        ['type' => 'text', 'attrs' => ['content' => 'x &lt;!--vm:endif--&gt; y']],
    ]));

    expect($text)->not->toContain('<!--vm:endif-->');
});

/*
 | B1 · KAMPÁNY: merge-változók és szegmens-feltétel, determinisztikus újrafutás
 */

it('B1: a kampány címzettenként helyettesít és szegmens szerint dönt (FakeDriver törzsek)', function () {
    $list = ContactList::create(['name' => 'Lista']);
    $segment = Segment::query()->create(['name' => 'VIP', 'definition' => ['match' => 'all', 'rules' => [
        ['field' => 'email', 'op' => 'contains', 'value' => 'vip'],
    ]]]);

    rvSubscriber('vip@pelda.hu', $list, ['first_name' => 'Anna', 'attributes' => ['company' => 'Kávé Kft.', 'titok' => 'NEM-KELL']]);
    rvSubscriber('sima@pelda.hu', $list, ['first_name' => 'Béla']);

    $template = Template::create(['name' => 'Kampány', 'marketing' => true, 'blocks' => rvTree([
        ['type' => 'text', 'attrs' => ['content' => 'Szia {{ contact.first_name || vendég }} ({{ contact.company || – }})']],
        ['type' => 'text', 'attrs' => ['content' => 'VIP kupon', 'condition' => ['kind' => 'segment', 'segment' => (string) $segment->uuid, 'op' => 'in']]],
    ])]);

    app(StartCampaign::class)(Campaign::create([
        'template_id' => $template->getKey(), 'name' => 'Ősz', 'subject' => 'Ősz', 'from_email' => 'no-reply@pelda.hu',
        'target_lists' => [(string) $list->uuid],
    ]));

    expect(rvBody('vip@pelda.hu'))->toContain('Szia Anna (Kávé Kft.)')->toContain('VIP kupon')
        ->and(rvBody('sima@pelda.hu'))->toContain('Szia Béla (–)')->not->toContain('VIP kupon');
});

it('B1: újrafutás megváltozott kontakt-adattal NEM küld újra, és a címzett sent marad', function () {
    $list = ContactList::create(['name' => 'Lista']);
    $anna = rvSubscriber('anna@pelda.hu', $list, ['first_name' => 'Anna']);

    $template = Template::create(['name' => 'Kampány', 'marketing' => true, 'blocks' => rvTree([
        ['type' => 'text', 'attrs' => ['content' => 'Szia {{ contact.first_name }}']],
    ])]);

    $campaign = app(StartCampaign::class)(Campaign::create([
        'template_id' => $template->getKey(), 'name' => 'Ősz', 'subject' => 'Ősz', 'from_email' => 'no-reply@pelda.hu',
        'target_lists' => [(string) $list->uuid],
    ]));

    expect(Message::query()->count())->toBe(1);

    // A KÖTEG ÚJRAFUT (pl. a worker a számlálók előtt halt meg): a sor pending, a név más.
    $campaign->recipients()->update(['status' => 'pending', 'sent_at' => null]);
    $anna->forceFill(['first_name' => 'Annamária'])->save();
    $campaign->forceFill(['status' => 'sending'])->saveQuietly();

    app()->call([new SendCampaignChunk((string) $campaign->uuid, (int) $campaign->workspace_id, $campaign->recipients()->pluck('id')->all()), 'handle']);

    expect(Message::query()->count())->toBe(1)
        ->and(FakeDriver::$sent)->toHaveCount(1)
        ->and($campaign->recipients()->first()->status)->toBe('sent');
});

it('S11: a RATE_LIMITED címzett a limit lejárta után a második futáson kimegy', function () {
    $list = ContactList::create(['name' => 'Lista']);
    rvSubscriber('anna@pelda.hu', $list);

    $template = Template::create(['name' => 'Kampány', 'marketing' => true, 'blocks' => rvTree([['type' => 'text', 'attrs' => ['content' => 'Szia']]])]);

    Bus::fake();
    $campaign = app(StartCampaign::class)(Campaign::create([
        'template_id' => $template->getKey(), 'name' => 'Ősz', 'subject' => 'Ősz', 'from_email' => 'no-reply@pelda.hu',
        'target_lists' => [(string) $list->uuid],
    ]));

    $key = 'sending:'.$campaign->workspace_id;
    RateLimiter::increment($key, 60, (int) config('sending.rate_limit.per_minute'));

    $job = fn () => new SendCampaignChunk((string) $campaign->uuid, (int) $campaign->workspace_id, $campaign->recipients()->pluck('id')->all());
    app()->call([$job(), 'handle']);

    expect($campaign->recipients()->first()->status)->toBe('pending');

    RateLimiter::clear($key);
    app()->call([$job(), 'handle']);

    expect($campaign->recipients()->first()->status)->toBe('sent')
        ->and(FakeDriver::$sent)->toHaveCount(1);
});

it('S8: a journey send_email lépése is kap merge-változót', function () {
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request('anna@pelda.hu'));
    Contact::create(['email' => 'anna@pelda.hu', 'first_name' => 'Anna', 'status' => 'subscribed', 'subscribed_at' => now()]);

    $template = Template::create(['name' => 'Journey', 'subject' => 'Szia', 'marketing' => true, 'blocks' => rvTree([
        ['type' => 'text', 'attrs' => ['content' => 'Szia {{ contact.first_name || vendég }}']],
    ])]);

    $journey = rvJourney((string) $template->uuid);
    $runs = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu');

    app(JourneyEngine::class)->tick($runs[0]);

    expect($journey->exists)->toBeTrue()
        ->and(rvBody('anna@pelda.hu'))->toContain('Szia Anna');
});

function rvJourney(string $templateUuid): Journey
{
    $journey = Journey::create(['name' => 'J '.bin2hex(random_bytes(3)), 'trigger_type' => TriggerType::FormSignup->value]);
    $journey->forceFill(['status' => 'active'])->save();

    $step = JourneyStep::create(['journey_id' => $journey->getKey(), 'position' => 0, 'type' => 'send_email', 'config' => [
        'template' => $templateUuid, 'from_email' => 'hello@pelda.hu',
    ]]);

    $journey->forceFill(['first_step_id' => $step->getKey()])->save();

    return $journey->fresh();
}

/*
 | B2 · TÖRLÉS futó hivatkozással
 */

it('B2: futó kampány vagy aktív journey sablonja nem törölhető (422), a piszkozaté igen', function () {
    $list = ContactList::create(['name' => 'Lista']);
    $template = Template::create(['name' => 'Használt', 'marketing' => true, 'blocks' => rvTree([['type' => 'text', 'attrs' => ['content' => 'x']]])]);

    $campaign = Campaign::create([
        'template_id' => $template->getKey(), 'name' => 'Futó', 'subject' => 'S', 'from_email' => 'no-reply@pelda.hu',
        'target_lists' => [(string) $list->uuid],
    ]);

    $this->delete('/templates/'.$template->uuid)->assertRedirect();
    $template->restore();

    $campaign->forceFill(['status' => 'sending'])->saveQuietly();
    $this->deleteJson('/templates/'.$template->uuid)->assertStatus(422)->assertJsonValidationErrors('template');

    $campaign->forceFill(['status' => 'sent'])->saveQuietly();
    $other = Template::create(['name' => 'Journey-sablon', 'marketing' => true, 'blocks' => rvTree([['type' => 'text', 'attrs' => ['content' => 'x']]])]);
    rvJourney((string) $other->uuid);

    $this->deleteJson('/templates/'.$other->uuid)->assertStatus(422);
    expect(Template::query()->whereKey($other->id)->exists())->toBeTrue();
});

/*
 | D52 · a marketing sablon tesztje a campaign csatornán, szűk consent-kivétellel
 */

it('D52: a marketing sablon tesztje campaign csatornán, a consent-kivétel auditált', function () {
    $template = Template::create(['name' => 'Hírlevél', 'subject' => 'Ősz', 'marketing' => true, 'blocks' => rvTree([['type' => 'text', 'attrs' => ['content' => 'Szia {{ contact.first_name }}!']]])]);

    $this->postJson("/templates/{$template->uuid}/test-send", ['recipients' => ['bence@pelda.hu'], 'from_email' => 'hello@pelda.hu'], ['Idempotency-Key' => 'd52'])
        ->assertStatus(202);

    $message = Message::query()->latest('id')->firstOrFail();

    expect($message->channel)->toBe('campaign')
        ->and(FakeDriver::$sent[0]['channel'])->toBe('campaign')
        ->and(rvBody('bence@pelda.hu'))->toContain('Szia Szabó!')
        ->and(AuditLog::query()->where('action', 'sending.consent.template_test_exception')->exists())->toBeTrue();
});

it('D52: a tranzakciós sablon tesztje tx csatornán marad', function () {
    $template = Template::create(['name' => 'Számla', 'subject' => 'Számla', 'marketing' => false, 'blocks' => rvTree([['type' => 'text', 'attrs' => ['content' => 'x']]])]);

    $this->postJson("/templates/{$template->uuid}/test-send", ['recipients' => ['bence@pelda.hu'], 'from_email' => 'hello@pelda.hu'], ['Idempotency-Key' => 'd52-tx'])
        ->assertStatus(202);

    expect(FakeDriver::$sent[0]['channel'])->toBe('transactional');
});

it('D52: kikapcsolt marketing-küldésnél a marketing teszt elutasul, érthető okkal', function () {
    config(['sending.marketing_enabled' => false]);

    $template = Template::create(['name' => 'Hírlevél', 'subject' => 'Ősz', 'marketing' => true, 'blocks' => rvTree([['type' => 'text', 'attrs' => ['content' => 'x']]])]);

    $this->postJson("/templates/{$template->uuid}/test-send", ['recipients' => ['bence@pelda.hu'], 'from_email' => 'hello@pelda.hu'], ['Idempotency-Key' => 'd52-off'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'CHANNEL_DISABLED');

    expect(FakeDriver::$sent)->toBe([]);
});

it('D52: a consent-kivétel SZŰK — template_test jelölés mellett is csak belső címzettre', function () {
    $result = app(SendMessage::class)([
        'channel' => 'campaign', 'from_email' => 'hello@pelda.hu', 'to' => ['kulso@pelda.hu'], 'subject' => 'x',
        'html' => '<p>x</p>', 'options' => ['studio_origin' => 'template_test'], 'idempotency_key' => 'd52-kulso',
    ]);

    expect($result->decision->allowed)->toBeFalse()
        ->and($result->decision->reason)->toBe('NO_CONSENT');
});

/*
 | S6 / SS2 / SS1 · browser_url és a webes nézet
 */

it('S6: a browser_url csak Studio-eredetű törzsre fenntartott — a /v1 hívó saját értéke nyer', function () {
    app(SendMessage::class)([
        'channel' => 'transactional', 'from_email' => 'hello@pelda.hu', 'to' => ['api@pelda.hu'], 'subject' => 'API',
        'html' => '<a href="{{ browser_url }}">web</a>', 'variables' => ['browser_url' => 'https://ugyfel.example/sajat'],
        'idempotency_key' => 'bu-1',
    ]);

    expect(rvBody('api@pelda.hu'))->toContain('https://ugyfel.example/sajat');
});

it('SS2: lejárt token és törölt törzs → 410; a válaszban nincs süti, a CSP sandboxol', function () {
    $template = Template::create(['name' => 'Web', 'subject' => 'Web', 'marketing' => false, 'blocks' => rvTree([['type' => 'text', 'attrs' => ['content' => 'Webes tartalom']]])]);
    $this->postJson("/templates/{$template->uuid}/test-send", ['recipients' => ['bence@pelda.hu'], 'from_email' => 'hello@pelda.hu'], ['Idempotency-Key' => 'web'])->assertStatus(202);

    $message = Message::query()->latest('id')->firstOrFail();
    $token = app(WebVersionTokens::class)->issue((int) $this->workspace->id, (string) $message->uuid);

    auth()->logout();

    $ok = $this->get('/w/'.$token)->assertOk()->assertSee('Webes tartalom');
    expect($ok->headers->get('Content-Security-Policy'))->toStartWith('sandbox;')
        ->and($ok->headers->getCookies())->toBe([]);

    $this->travel(31)->days();
    $this->get('/w/'.$token)->assertStatus(410);
    $this->travelBack();

    $message->forceFill(['options' => [...$message->options, 'body_html' => null]])->save();
    $this->get('/w/'.$token)->assertStatus(410);
});

/*
 | S1 / S2 · lábléc
 */

it('S1: a helyőrző postai cím hiányzónak számít — az optimalizáló mutatja, a kampány nem indul', function () {
    $placeholder = (string) __('editor.defaults.footerAddress', [], 'de');
    $blocks = rvTree([['type' => 'text', 'attrs' => ['content' => 'x']]], $placeholder);

    expect(app(TemplateCompliance::class)->missing($blocks))->toBe(['postal_address'])
        ->and(app(TemplateCompliance::class)->missing($blocks, strict: false))->toBe([]);

    $template = Template::create(['name' => 'Helyőrző', 'subject' => 'S', 'marketing' => true, 'blocks' => $blocks]);
    $list = ContactList::create(['name' => 'Lista']);

    expect(fn () => app(StartCampaign::class)(Campaign::create([
        'template_id' => $template->getKey(), 'name' => 'K', 'subject' => 'S', 'from_email' => 'no-reply@pelda.hu',
        'target_lists' => [(string) $list->uuid],
    ])))->toThrow(InvalidArgumentException::class, 'postai cím');
});

it('S2: a lábléces szakasz nem rejthető el mobilon', function () {
    $blocks = rvTree([['type' => 'text', 'attrs' => ['content' => 'x']]]);
    $blocks[1]['attrs'] = ['hide_on_mobile' => true];

    expect(implode(' ', app(BlockTreeValidator::class)->errorsIn($blocks)))->toContain('nem rejthető el mobilon');
});

/*
 | S4 / S5 · audit, N+1
 */

it('S4: az autosave tömör template.autosaved sort ír, a fordított HTML nem kerül az auditba', function () {
    $template = Template::create(['name' => 'Audit', 'marketing' => true, 'blocks' => rvTree([['type' => 'text', 'attrs' => ['content' => 'első']]])]);
    $before = AuditLog::query()->where('action', 'updated')->count();

    $this->putJson("/templates/{$template->uuid}/autosave", ['blocks' => rvTree([['type' => 'text', 'attrs' => ['content' => 'második']]])])->assertOk();

    expect(AuditLog::query()->where('action', 'updated')->count())->toBe($before)
        ->and(AuditLog::query()->where('action', 'template.autosaved')->latest('id')->value('context'))->toHaveKeys(['render_hash', 'version']);

    $this->putJson("/templates/{$template->uuid}/autosave", ['blocks' => rvTree([['type' => 'text', 'attrs' => ['content' => 'harmadik']]]), 'manual' => true])->assertOk();

    $updated = AuditLog::query()->where('action', 'updated')->latest('id')->firstOrFail();
    expect(json_encode($updated->after))->not->toContain('<!doctype')->and($updated->after['html'] ?? '[redacted]')->toBe('[redacted]');
});

it('S5: a könyvtár verziószáma egy lekérdezéssel jön (nincs N+1)', function () {
    foreach (range(1, 5) as $i) {
        Template::create(['name' => "T{$i}", 'marketing' => false, 'blocks' => []]);
    }

    DB::enableQueryLog();
    $this->get('/templates')->assertOk();
    $versionQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'template_versions'))->count();

    expect($versionQueries)->toBe(1);
});

/*
 | SS4 · DBL gyorsítótár és workspace-keret
 */

it('SS4: a domain-feketelista hosztonként gyorsítótárazott', function () {
    config(['deliverability.spam_score.domain_blocklist_zone' => 'dbl.example']);
    $fake = new FakeBlocklistClient;
    app()->instance(BlocklistClient::class, $fake);

    $scorer = app(SpamScorer::class);
    $scorer->score('S', '<p>x</p>', 'x', ['https://valami.example/a']);
    $scorer->score('S', '<p>x</p>', 'x', ['https://valami.example/b']);

    expect($fake->checked)->toHaveCount(1);

    $scorer->score('S', '<p>x</p>', 'x', ['https://masik.example/a'], lookupDomains: false);
    expect($fake->checked)->toHaveCount(1);
});

/*
 | SS5 · képek: metaadat nélkül, kvóta, törölt workspace takarítása
 */

it('SS5: a feltöltött JPEG metaadat (EXIF/GPS) nélkül tárolódik, és a kvóta érvényes', function () {
    Storage::fake('public');

    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    $exif = "Exif\0\0GPS-TITOK-47.4979N";
    $withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);

    $uuid = $this->post('/template-assets', ['file' => UploadedFile::fake()->createWithContent('foto.jpg', $withExif)], ['Accept' => 'application/json'])
        ->assertCreated()->json('uuid');

    $asset = TemplateAsset::query()->where('uuid', $uuid)->firstOrFail();
    expect(Storage::disk('public')->get($asset->path))->not->toContain('GPS-TITOK');

    config(['template.assets.workspace_quota_bytes' => 10]);
    $this->post('/template-assets', ['file' => UploadedFile::fake()->createWithContent('foto2.jpg', $jpeg)], ['Accept' => 'application/json'])
        ->assertStatus(422);
});

it('SS5: a törölt workspace sablon-képei a takarító paranccsal törlődnek', function () {
    Storage::fake('public');
    Storage::disk('public')->put('templates/'.$this->workspace->uuid.'/x.png', 'kép');
    TemplateAsset::query()->create(['driver' => 'local', 'path' => 'templates/'.$this->workspace->uuid.'/x.png', 'url' => 'https://x/x.png', 'mime' => 'image/png', 'size' => 3, 'width' => 1, 'height' => 1]);

    $this->workspace->delete();
    app(WorkspaceContext::class)->forget();

    $this->artisan('templates:purge-assets')->assertSuccessful();

    Storage::disk('public')->assertMissing('templates/'.$this->workspace->uuid.'/x.png');
    expect(app(WorkspaceContext::class)->runFor($this->workspace, fn () => TemplateAsset::query()->count()))->toBe(0);
});

/*
 | NIT · lábléc felirata a workspace nyelvén, MergeVariables adatminimalizálás
 */

it('NIT: a lábléc fallback-felirata a workspace nyelvén jön', function () {
    $this->workspace->forceFill(['settings' => ['locale' => 'de']])->save();
    app(WorkspaceContext::class)->set($this->workspace->fresh());

    $html = app(MjmlCompiler::class)->compile(app(MjmlWriter::class)->write(rvTree([['type' => 'text', 'attrs' => ['content' => 'x']]])));

    expect($html)->toContain('Abmelden')->toContain('E-Mail-Einstellungen');
});

it('NIT: csak a sablonban hivatkozott egyéni mezők utaznak a renderelési adatban', function () {
    $contact = Contact::create(['email' => 'a@pelda.hu', 'status' => 'subscribed', 'attributes' => ['city' => 'Szeged', 'titok' => 'X']]);
    $variables = app(MergeVariables::class);
    $variables->prepare(['Szia {{ contact.attributes.city }}'], [$contact->id]);

    expect($variables->forContact($contact, $this->workspace)['contact']['attributes'])->toBe(['city' => 'Szeged']);
});

it('NIT: hosszú Idempotency-Key-jel is működik a teszt-küldés (a kulcs hash-elt)', function () {
    $template = Template::create(['name' => 'Hosszú', 'subject' => 'S', 'marketing' => false, 'blocks' => rvTree([['type' => 'text', 'attrs' => ['content' => 'x']]])]);

    $this->postJson("/templates/{$template->uuid}/test-send", ['recipients' => ['bence@pelda.hu'], 'from_email' => 'hello@pelda.hu'], ['Idempotency-Key' => str_repeat('k', 255)])
        ->assertStatus(202);

    expect(strlen((string) Message::query()->latest('id')->value('idempotency_key')))->toBeLessThan(100);
});

it('NIT: legfeljebb 200 mentett blokk', function () {
    foreach (range(1, SavedBlockController::MAX_BLOCKS) as $i) {
        SavedBlock::query()->create(['name' => "B{$i}", 'kind' => 'block', 'block' => ['type' => 'spacer']]);
    }

    $this->postJson('/template-blocks', ['name' => 'Egy túl sok', 'kind' => 'block', 'block' => ['type' => 'spacer']])->assertStatus(422);
});

it('a sablonszerkesztő limiterei ablakonként külön kulcsot kapnak', function () {
    $request = Request::create('/x', 'POST');
    $request->setUserResolver(fn () => $this->user);

    foreach (['template-editor', 'template-test-send', 'template-assets'] as $name) {
        $limits = (RateLimiter::limiter($name))($request);
        $keys = array_map(fn ($limit) => $limit->key, is_array($limits) ? $limits : [$limits]);

        expect($keys)->not->toBeEmpty()
            ->and(count(array_unique($keys)))->toBe(count($keys), "közös kulcs: {$name}");
    }
});
