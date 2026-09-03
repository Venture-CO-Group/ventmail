<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Events\Services\TrackingTokens;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
 | A3 · 23. és 26. kritérium — a lánc UTOLSÓ két szeme: `opened` és `clicked`.
 |
 | Enélkül a trace a `delivered`-nél megáll, a tag-statisztika open/click aránya pedig
 | sosem tudna mit mutatni. Ezek az események nem keletkeznek maguktól: a levélbe bele kell
 | írni azt, ami majd meghívja őket.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    FakeDriver::reset();
});

/** Amit a driver LÁTOTT — nem amit az ügyfél beküldött. */
function sentHtml(string $recipient = 'cimzett@example.test'): string
{
    return (string) (FakeDriver::$sent[0]['bodies'][$recipient]['html'] ?? '');
}

function sendTracked(array $overrides = []): Message
{
    return app(SendMessage::class)(array_merge([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Számla',
        'to' => ['cimzett@example.test'],
        'html' => '<html><body><p>Szia</p><a href="https://ugyfel.example.test/szamla/42">Számla</a></body></html>',
        'tags' => ['szamla'],
        'idempotency_key' => 'tr-'.bin2hex(random_bytes(5)),
    ], $overrides))->message->fresh();
}

it('a kiment levélbe bekerül a nyitás-pixel és a becsomagolt link', function () {
    $message = sendTracked();

    $kikuldott = sentHtml();

    // A PIXEL bent van, és a `</body>` ELŐTT — a levélkliensek egy része a záró tag utáni
    // tartalmat eldobja.
    expect($kikuldott)->toContain('/t/o/');
    expect($kikuldott)->toContain('width="1" height="1"');
    expect(strpos($kikuldott, '/t/o/'))->toBeLessThan((int) strpos($kikuldott, '</body>'));

    // A LINK becsomagolva megy ki; az eredeti cím nem látszik a `href`-ben.
    expect($kikuldott)->toContain('/t/c/');
    expect($kikuldott)->not->toContain('href="https://ugyfel.example.test/szamla/42"');

    // …de a becsomagolt link a HELYES célra visz.
    preg_match('#/t/c/([A-Za-z0-9\-_~]+)#', $kikuldott, $egyezes);

    expect(app(TrackingTokens::class)->parse($egyezes[1])?->destination)
        ->toBe('https://ugyfel.example.test/szamla/42');

    // A TÁROLT törzs viszont ÉRINTETLEN: az ügyfél a trace-en azt látja, amit beküldött.
    expect((string) ($message->options['body_html'] ?? ''))
        ->toContain('href="https://ugyfel.example.test/szamla/42"')
        ->not->toContain('/t/o/');
});

it('az `o:tracking=no` mindent kikapcsol', function () {
    // Tranzakciós levélben (jelszó-visszaállítás, számla) a nyomkövetés gyakran jogi vagy
    // adatvédelmi okból nem kívánatos.
    sendTracked(['options' => ['tracking' => false]]);

    expect(sentHtml())->not->toContain('/t/o/');
    expect(sentHtml())->not->toContain('/t/c/');
    expect(sentHtml())->toContain('href="https://ugyfel.example.test/szamla/42"');
});

it('a nyitás- és a klikk-követés KÜLÖN is kikapcsolható', function () {
    sendTracked(['options' => ['tracking_opens' => false]]);

    expect(sentHtml())->not->toContain('/t/o/');
    expect(sentHtml())->toContain('/t/c/');

    FakeDriver::reset();

    sendTracked(['options' => ['tracking_clicks' => false]]);

    expect(sentHtml())->toContain('/t/o/');
    expect(sentHtml())->not->toContain('/t/c/');
});

it('a LEIRATKOZÁSI linket nem csomagolja be', function () {
    // Ha a nyomkövetés bármikor elromlana, a leiratkozásnak akkor is működnie kell: egy nem
    // működő leiratkozás panaszt szül, a panasz pedig a küldő reputációját rombolja.
    sendTracked([
        'html' => '<html><body><a href="https://pelda.hu/unsubscribe/abc">Leiratkozás</a></body></html>',
    ]);

    expect(sentHtml())->toContain('href="https://pelda.hu/unsubscribe/abc"');
});

it('a mailto és a horgony érintetlen marad', function () {
    sendTracked([
        'html' => '<html><body><a href="mailto:info@pelda.hu">Írj</a><a href="#lent">Lent</a></body></html>',
    ]);

    expect(sentHtml())->toContain('href="mailto:info@pelda.hu"');
    expect(sentHtml())->toContain('href="#lent"');
});

it('a nyomkövetés CÍMZETTENKÉNT más — különben nem lehetne tudni, KI nyitotta meg', function () {
    sendTracked(['to' => ['egyik@example.test', 'masik@example.test']]);

    preg_match('#/t/o/([A-Za-z0-9\-_~]+)#', sentHtml('egyik@example.test'), $egyik);
    preg_match('#/t/o/([A-Za-z0-9\-_~]+)#', sentHtml('masik@example.test'), $masik);

    expect($egyik[1])->not->toBe($masik[1]);
    expect(app(TrackingTokens::class)->parse($egyik[1])?->recipient)->toBe('egyik@example.test');
    expect(app(TrackingTokens::class)->parse($masik[1])?->recipient)->toBe('masik@example.test');
});

it('a nyitás-pixel `opened` eseményt ír', function () {
    $message = sendTracked();

    $token = app(TrackingTokens::class)->issue(
        (int) $this->workspace->getKey(),
        (string) $message->uuid,
        'cimzett@example.test',
    );

    $response = $this->get('/t/o/'.$token);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/gif');

    app(WorkspaceContext::class)->set($this->workspace);

    expect(MessageEvent::query()->where('type', 'opened')->count())->toBe(1);
    expect(MessageEvent::query()->where('type', 'opened')->first()->recipient)->toBe('cimzett@example.test');
});

it('a kattintás `clicked` eseményt ír, és a CÉLRA irányít', function () {
    $message = sendTracked();

    $token = app(TrackingTokens::class)->issue(
        (int) $this->workspace->getKey(),
        (string) $message->uuid,
        'cimzett@example.test',
        'https://ugyfel.example.test/szamla/42',
    );

    $this->get('/t/c/'.$token)->assertRedirect('https://ugyfel.example.test/szamla/42');

    app(WorkspaceContext::class)->set($this->workspace);

    expect(MessageEvent::query()->where('type', 'clicked')->count())->toBe(1);
});

it('a klikk-végpont NEM nyílt átirányító', function () {
    // A cél a TOKENEN BELÜL van, titkosítva. Ha kívülről megadható lenne, a
    // `ventmail.app/t/c/…` egy közönséges nyílt átirányító lenne — épp azon a domainen,
    // aminek a jó híre a kézbesíthetőségünk alapja.
    $this->get('/t/c/'.urlencode('https://tamado.example.test'))->assertNotFound();
    $this->get('/t/c/hamis-token')->assertNotFound();

    // …és cél nélküli (nyitás-) tokennel sem irányít.
    $message = sendTracked();

    $nyitasToken = app(TrackingTokens::class)->issue(
        (int) $this->workspace->getKey(),
        (string) $message->uuid,
        'cimzett@example.test',
    );

    $this->get('/t/c/'.$nyitasToken)->assertNotFound();
});

it('a hamis token UGYANAZT a választ kapja, mint a jó', function () {
    // A különbség orákulum lenne a token érvényességére — és a pixel válasza amúgy is
    // ugyanaz kell legyen, különben a levélkliens törött képet mutatna.
    $response = $this->get('/t/o/teljesen-hamis-token');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/gif');

    app(WorkspaceContext::class)->set($this->workspace);

    expect(MessageEvent::query()->where('type', 'opened')->count())->toBe(0);
});

it('a token nem árulja el a címzett címét', function () {
    // A link proxykon, vírusszűrőkön és böngésző-előzményeken megy át.
    $message = sendTracked();

    $token = app(TrackingTokens::class)->issue(
        (int) $this->workspace->getKey(),
        (string) $message->uuid,
        'cimzett@example.test',
    );

    expect($token)->not->toContain('cimzett');
    expect($token)->not->toContain('example.test');
    expect(base64_decode(strtr($token, '-_~', '+/='), false) ?: '')->not->toContain('cimzett@example.test');

    // …de nekünk visszafejthető.
    expect(app(TrackingTokens::class)->parse($token)?->recipient)->toBe('cimzett@example.test');
});

it('a percen belüli ismétlés EGY esemény', function () {
    // A pixelt a levélkliens minden megjelenítéskor újratölti; enélkül egyetlen megnyitott
    // levél percenként több száz sort írna a hypertable-be.
    Cache::clear();

    $message = sendTracked();

    $token = app(TrackingTokens::class)->issue(
        (int) $this->workspace->getKey(),
        (string) $message->uuid,
        'cimzett@example.test',
    );

    foreach (range(1, 5) as $ignored) {
        $this->get('/t/o/'.$token)->assertOk();
    }

    app(WorkspaceContext::class)->set($this->workspace);

    expect(MessageEvent::query()->where('type', 'opened')->count())->toBe(1);
});

it('IDEGEN workspace üzenetére nem ír eseményt', function () {
    $message = sendTracked();

    $masik = Workspace::factory()->create();

    // A token workspace-e nem egyezik az üzenetével: a kontextus a tokenből jön, az üzenet
    // pedig a scope-olt lekérdezésből — így nem található, és nem keletkezik esemény.
    $token = app(TrackingTokens::class)->issue(
        (int) $masik->getKey(),
        (string) $message->uuid,
        'cimzett@example.test',
    );

    $this->get('/t/o/'.$token)->assertOk();

    app(WorkspaceContext::class)->set($masik);
    expect(MessageEvent::query()->where('type', 'opened')->count())->toBe(0);

    app(WorkspaceContext::class)->set($this->workspace);
    expect(MessageEvent::query()->where('type', 'opened')->count())->toBe(0);
});

it('a végpont nem hagy maga után tenant-kontextust', function () {
    // NYILVÁNOS végpont: a bent felejtett kontextus a következő, akár egészen más tenanthoz
    // tartozó kérést szennyezné.
    $message = sendTracked();

    $token = app(TrackingTokens::class)->issue(
        (int) $this->workspace->getKey(),
        (string) $message->uuid,
        'cimzett@example.test',
    );

    app(WorkspaceContext::class)->forget();

    $this->get('/t/o/'.$token)->assertOk();

    expect(app(WorkspaceContext::class)->hasWorkspace())->toBeFalse();
});
