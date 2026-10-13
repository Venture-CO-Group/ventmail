<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Api\Http\Middleware\RequireIdempotencyKey;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Campaign\Actions\StartCampaign;
use App\Modules\Campaign\Jobs\SendCampaignChunk;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Enums\Role;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRunStep;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Journey\Services\JourneyEngine;
use App\Modules\Journey\Services\JourneyTrigger;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\StoredTemplates\Services\ConditionalContent;
use App\Modules\Template\Actions\PublishToStoredTemplate;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Models\TemplateAsset;
use App\Modules\Template\Services\Assets\GifMetadata;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | v1.1 · 4. modul — a security újra-review maradék pontjai (S1–S4 + NIT-ek).
 */

beforeEach(function () {
    $this->withoutVite();
    FakeDriver::reset();

    $this->workspace = Workspace::factory()->create(['name' => 'Nádor Kávéház']);
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->user = User::factory()->create(['email' => 'bence@pelda.hu']);
    $this->workspace->members()->create(['user_id' => $this->user->id, 'roles' => [Role::Owner->value]]);
    $this->actingAs($this->user)->withSession(['workspace_uuid' => $this->workspace->uuid]);
});

function r2Tree(string $text = 'Szia'): array
{
    return [
        ['type' => 'section', 'children' => [['type' => 'column', 'children' => [['type' => 'text', 'attrs' => ['content' => $text]]]]]],
        ['type' => 'section', 'children' => [['type' => 'column', 'children' => [['type' => 'footer', 'attrs' => ['address' => '1000 Budapest, Valódi utca 7.']]]]]],
    ];
}

function r2Jpeg(): string
{
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

function r2Upload(string $name, string $bytes): TestResponse
{
    return test()->post('/template-assets', ['file' => UploadedFile::fake()->createWithContent($name, $bytes)], ['Accept' => 'application/json']);
}

/*
 | S1 · kvóta zárral, és a sor-írás hibájánál nem marad árva fájl
 */

it('S1: ha a nyilvántartás írása elbukik, a már kiírt fájl is törlődik', function () {
    Storage::fake('public');
    TemplateAsset::creating(static fn () => throw new RuntimeException('db hiba'));

    expect(fn () => $this->withoutExceptionHandling()->post('/template-assets', ['file' => UploadedFile::fake()->createWithContent('a.jpg', r2Jpeg())]))
        ->toThrow(RuntimeException::class, 'db hiba');

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('S1: a kvóta-láncot workspace-zár védi — foglalt zárnál a feltöltés érthető hibát ad', function () {
    Storage::fake('public');
    config(['template.assets.lock_wait_seconds' => 0]);

    $lock = Cache::lock('template-assets:'.$this->workspace->getKey(), 30);
    $lock->get();

    r2Upload('a.jpg', r2Jpeg())->assertStatus(422)->assertJsonValidationErrors('file');
    expect(TemplateAsset::query()->count())->toBe(0);

    $lock->release();
    r2Upload('a.jpg', r2Jpeg())->assertCreated();
});

it('a képfeltöltés plafonja a globális törzs-limit alatt marad (a multipart-ráhagyással)', function () {
    expect((int) config('template.assets.max_bytes') + 64 * 1024)->toBeLessThan((int) config('app.max_body_bytes'));
});

/*
 | S2 · progresszív JPEG scan-plafon
 */

it('S2: a túl sok scan-t (SOS) tartalmazó JPEG elutasul dekódolás előtt', function () {
    Storage::fake('public');

    $jpeg = r2Jpeg();
    $payload = str_repeat("\xFF\xDA", 101);
    $bomb = substr($jpeg, 0, 2)."\xFF\xFE".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);

    r2Upload('bomba.jpg', $bomb)->assertStatus(422);
    r2Upload('rendes.jpg', $jpeg)->assertCreated();
});

/*
 | NIT · GIF metaadat
 */

it('GIF: a komment és az XMP kiesik, az animációs ismétlés és a kép marad', function () {
    Storage::fake('public');

    $image = imagecreate(2, 2);
    imagecolorallocate($image, 0, 0, 0);
    ob_start();
    imagegif($image);
    $gif = (string) ob_get_clean();

    $flags = ord($gif[10]);
    $offset = 13 + (($flags & 0x80) ? 3 * (2 ** (($flags & 0x07) + 1)) : 0);

    $comment = "\x21\xFE\x0AGPS-TITKOS\x00";
    $xmp = "\x21\xFF\x0BXMP DataXMP\x08<x>titok\x00";
    $loop = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";

    $dirty = substr($gif, 0, $offset).$comment.$xmp.$loop.substr($gif, $offset);
    $clean = GifMetadata::strip($dirty);

    expect($clean)->not->toContain('GPS-TITKOS')->not->toContain('XMP DataXMP')->toContain('NETSCAPE2.0')
        ->and(getimagesizefromstring($clean)[0] ?? null)->toBe(2);

    $uuid = r2Upload('anim.gif', $dirty)->assertCreated()->json('uuid');
    expect(Storage::disk('public')->get(TemplateAsset::query()->where('uuid', $uuid)->value('path')))->not->toContain('GPS-TITKOS');

    expect(fn () => GifMetadata::strip(substr($dirty, 0, -5)))->toThrow(InvalidArgumentException::class);
});

/*
 | S3 · takarítás: türelmi idő, élő workspace érintetlen
 */

it('S3: csak a türelmi időn túl törölt workspace képei tűnnek el — az élőé és a frissen törölté marad', function () {
    Storage::fake('public');

    $make = function (Workspace $workspace, string $file): void {
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $file): void {
            Storage::disk('public')->put("templates/{$workspace->uuid}/{$file}", 'x');
            foreach (range(1, 3) as $i) {
                TemplateAsset::query()->create(['driver' => 'local', 'path' => "templates/{$workspace->uuid}/{$file}", 'url' => 'https://x/'.$file, 'mime' => 'image/png', 'size' => 1, 'width' => 1, 'height' => 1]);
            }
        });
    };

    $old = Workspace::factory()->create();
    $recent = Workspace::factory()->create();
    $make($this->workspace, 'el.png');
    $make($old, 'regi.png');
    $make($recent, 'friss.png');

    $old->delete();
    DB::table('workspaces')->where('id', $old->id)->update(['deleted_at' => now()->subDays(31)]);
    $recent->delete();

    $this->artisan('templates:purge-assets')->assertSuccessful();

    $count = fn (Workspace $workspace) => app(WorkspaceContext::class)->runFor($workspace, fn () => TemplateAsset::query()->count());

    expect($count($this->workspace))->toBe(3)
        ->and($count($recent))->toBe(3)
        ->and($count($old))->toBe(0);

    Storage::disk('public')->assertExists("templates/{$this->workspace->uuid}/el.png");
    Storage::disk('public')->assertMissing("templates/{$old->uuid}/regi.png");
});

/*
 | S4 · átmeneti újrapróbálás VALÓDI sor-szemantikával (worker, tries=1)
 */

function r2Campaign(): Campaign
{
    $list = ContactList::create(['name' => 'Lista']);
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request('anna@pelda.hu'));
    $contact = Contact::create(['email' => 'anna@pelda.hu', 'status' => 'subscribed', 'subscribed_at' => now()]);
    $contact->lists()->attach($list, ['status' => 'subscribed', 'subscribed_at' => now()]);

    $template = Template::create(['name' => 'Kampány', 'marketing' => true, 'blocks' => r2Tree()]);

    // A Bus fake CSAK a StartCampaign kötegét fogja; utána a valódi dispatcher kell, mert a
    // worker a `dispatchNow`-val futtatja a jobot.
    $real = app(Dispatcher::class);
    Bus::fake();
    $campaign = app(StartCampaign::class)(Campaign::create([
        'template_id' => $template->getKey(), 'name' => 'Ősz', 'subject' => 'Ősz', 'from_email' => 'no-reply@pelda.hu',
        'target_lists' => [(string) $list->uuid],
    ]));
    Bus::swap($real);

    // A JOB a VALÓDI adatbázis-sorba kerül (a Bus fake csak a StartCampaign kötegét fogja).
    // `beforeCommit`: a teszt a RefreshDatabase tranzakciójában fut, ami sosem commitol.
    Queue::connection('database')->pushOn(
        'r2',
        (new SendCampaignChunk((string) $campaign->uuid, (int) $campaign->workspace_id, $campaign->recipients()->pluck('id')->all()))->beforeCommit(),
    );

    return $campaign;
}

function r2Work(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'r2', '--once' => true, '--tries' => 1, '--sleep' => 0]);
}

it('S4: RATE_LIMITED után a worker (tries=1) a késleltetés letelte után újra felveszi, és kimegy', function () {
    $campaign = r2Campaign();
    $key = 'sending:'.$campaign->workspace_id;
    RateLimiter::increment($key, 60, (int) config('sending.rate_limit.per_minute'));

    r2Work();

    expect($campaign->recipients()->first()->status)->toBe('pending')
        ->and(DB::table('jobs')->where('queue', 'r2')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    RateLimiter::clear($key);
    $this->travel(61)->seconds();
    r2Work();

    expect($campaign->recipients()->first()->status)->toBe('sent')
        ->and(FakeDriver::$sent)->toHaveCount(1)
        ->and(DB::table('jobs')->where('queue', 'r2')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('S4: a kísérlet-plafon után a címzett failed (transient_exhausted), a job nem pörög tovább', function () {
    $campaign = r2Campaign();
    $key = 'sending:'.$campaign->workspace_id;

    foreach (range(1, SendCampaignChunk::MAX_TRANSIENT_ATTEMPTS) as $round) {
        RateLimiter::increment($key, 3600, (int) config('sending.rate_limit.per_minute'));
        r2Work();
        $this->travel(61)->seconds();
    }

    $recipient = $campaign->recipients()->first();

    expect($recipient->status)->toBe('failed')
        ->and($recipient->skip_reason)->toBe('transient_exhausted:RATE_LIMITED')
        ->and(DB::table('jobs')->where('queue', 'r2')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(FakeDriver::$sent)->toBe([]);
});

/*
 | NIT · journey replay, fenntartott kulcs-előtagok, /v1 jelölők
 */

it('journey: IDEMPOTENCY_KEY_REUSED visszajátszás — az előző üzenet dönt, nincs második levél', function () {
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request('anna@pelda.hu'));
    Contact::create(['email' => 'anna@pelda.hu', 'first_name' => 'Anna', 'status' => 'subscribed', 'subscribed_at' => now()]);

    $template = Template::create(['name' => 'J', 'subject' => 'J', 'marketing' => true, 'blocks' => r2Tree()]);
    $journey = Journey::create(['name' => 'J', 'trigger_type' => TriggerType::FormSignup->value]);
    $journey->forceFill(['status' => 'active'])->save();
    $step = JourneyStep::create(['journey_id' => $journey->getKey(), 'position' => 0, 'type' => 'send_email', 'config' => ['template' => (string) $template->uuid, 'from_email' => 'hello@pelda.hu']]);
    $journey->forceFill(['first_step_id' => $step->getKey()])->save();

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    // Egy korábbi kísérlet ugyanazzal a belső kulccsal, MÁS renderelési adattal.
    $earlier = app(SendMessage::class)([
        'channel' => 'transactional', 'from_email' => 'hello@pelda.hu', 'to' => ['anna@pelda.hu'], 'subject' => 'korábbi',
        'html' => '<p>korábbi</p>', 'idempotency_key' => 'journey:'.$run->uuid.':'.$step->uuid.':1',
    ]);

    app(JourneyEngine::class)->tick($run);

    $claim = JourneyRunStep::query()->where('journey_run_id', $run->id)->firstOrFail();

    expect($claim->outcome)->toBe('sent')
        ->and($claim->message_uuid)->toBe($earlier->message->uuid)
        ->and(FakeDriver::$sent)->toHaveCount(1);
});

it('a /v1 fenntartott belső Idempotency-Key előtagokat 400-zal utasítja el', function () {
    $middleware = new RequireIdempotencyKey;

    foreach (RequireIdempotencyKey::RESERVED_PREFIXES as $prefix) {
        $request = Request::create('/v1/messages', 'POST');
        $request->headers->set('Idempotency-Key', strtoupper($prefix).'valami');

        $response = $middleware->handle($request, fn () => response('tovább'));

        expect($response->getStatusCode())->toBe(400, $prefix)
            ->and(json_decode((string) $response->getContent(), true)['error']['code'])->toBe('IDEMPOTENCY_KEY_RESERVED');
    }

    $ok = Request::create('/v1/messages', 'POST');
    $ok->headers->set('Idempotency-Key', 'order-123');
    expect($middleware->handle($ok, fn () => response('tovább'))->getContent())->toBe('tovább');
});

it('/v1 törzsben csak a vm: jelölő tűnik el — a tartalom (le nem zárt nyitó után is) marad', function () {
    $html = '<p>eleje</p><!--vm:if:AAAA-->közepe<!--vm:endif--><p>vége</p><!--vm:if:BBBB-->utolsó';

    expect(ConditionalContent::unmark($html))->toBe('<p>eleje</p>közepe<p>vége</p>utolsó');

    app(SendMessage::class)([
        'channel' => 'transactional', 'from_email' => 'hello@pelda.hu', 'to' => ['api@pelda.hu'], 'subject' => 'API',
        'html' => $html, 'idempotency_key' => 'v1-unmark',
    ]);

    expect((string) FakeDriver::$sent[0]['bodies']['api@pelda.hu']['html'])
        ->toContain('közepe')->toContain('utolsó')->not->toContain('vm:');
});

it('a tárolt sablonná publikálás kiveszi a feltételes blokkokat', function () {
    $blocks = r2Tree('mindenkinek');
    $blocks[0]['children'][0]['children'][] = ['type' => 'text', 'attrs' => ['content' => 'csak VIP', 'condition' => ['kind' => 'segment', 'segment' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', 'op' => 'in']]];

    $template = Template::create(['name' => 'Publikált', 'marketing' => true, 'blocks' => $blocks]);
    $version = app(PublishToStoredTemplate::class)($template);

    expect($version->body)->toContain('mindenkinek')->not->toContain('csak VIP')->not->toContain('vm:')
        ->and($version->body_text)->not->toContain('csak VIP');
});
