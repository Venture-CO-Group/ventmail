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

    // A nyomkövetés a tenant SAJÁT hosztján fut (`email.<domain>`), és a token ERRE a
    // hosztra szól. A teszt-kérés hosztja `localhost`, tehát a wizard által kért CNAME-et is
    // erre állítjuk — így a hoszt-kötést VALÓDI kérésen mérjük, nem megkerüljük.
    Domain::create([
        'name' => 'pelda.hu',
        'status' => 'verified',
        'verified_at' => now(),
        'tracking_domain' => 'localhost',
        'tracking_status' => 'verified',
        'tracking_enabled' => true,
    ]);

    FakeDriver::reset();
});

function trackingToken(string $messageUuid, string $recipient, ?string $destination = null, string $host = 'localhost'): string
{
    return app(TrackingTokens::class)->issue(
        (int) test()->workspace->getKey(),
        $messageUuid,
        $recipient,
        $host,
        $destination,
    );
}

/** Amit a driver LÁTOTT — nem amit az ügyfél beküldött. */
function sentHtml(string $recipient = 'cimzett@example.test'): string
{
    return (string) (FakeDriver::$sent[0]['bodies'][$recipient]['html'] ?? '');
}

function sendTracked(array $overrides = []): Message
{
    // A helper `transactional`-t küld, ahol a nyomkövetés ALAPÉRTELMEZÉSBEN KI van kapcsolva
    // (a jelszó-levél olvasásának ténye nem üzleti metrika). Az itteni esetek a nyomkövetés
    // VISELKEDÉSÉT mérik, tehát explicit bekapcsoljuk — a csatornánkénti alapértéknek külön
    // tesztje van.
    $overrides['options'] = array_merge(['tracking' => true], (array) ($overrides['options'] ?? []));

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

it('a TRANZAKCIÓS levél alapból NEM követett', function () {
    // Egy jelszó-visszaállító vagy számla-levél olvasásának ténye nem üzleti metrika, hanem a
    // címzett magánügye — és a hozzájárulása ehhez nincs meg. Aki mégis akarja, egyetlen
    // `o:tracking=yes`-szel bekapcsolja.
    app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Jelszó',
        'to' => ['cimzett@example.test'],
        'html' => '<html><body><a href="https://ugyfel.example.test/reset">Új jelszó</a></body></html>',
        'idempotency_key' => 'tr-'.bin2hex(random_bytes(5)),
    ]);

    expect(sentHtml())->not->toContain('/t/o/');
    expect(sentHtml())->toContain('href="https://ugyfel.example.test/reset"');
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

    $token = trackingToken((string) $message->uuid, 'cimzett@example.test');

    $response = $this->get('/t/o/'.$token);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/gif');

    app(WorkspaceContext::class)->set($this->workspace);

    expect(MessageEvent::query()->where('type', 'opened')->count())->toBe(1);
    expect(MessageEvent::query()->where('type', 'opened')->first()->recipient)->toBe('cimzett@example.test');
});

it('a kattintás `clicked` eseményt ír, és a CÉLRA irányít', function () {
    $message = sendTracked();

    $token = trackingToken((string) $message->uuid, 'cimzett@example.test', 'https://ugyfel.example.test/szamla/42');

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

    $nyitasToken = trackingToken((string) $message->uuid, 'cimzett@example.test');

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

    $token = trackingToken((string) $message->uuid, 'cimzett@example.test');

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

    $token = trackingToken((string) $message->uuid, 'cimzett@example.test');

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
    $token = app(TrackingTokens::class)->issue((int) $masik->getKey(), (string) $message->uuid, 'cimzett@example.test', 'localhost');

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

    $token = trackingToken((string) $message->uuid, 'cimzett@example.test');

    app(WorkspaceContext::class)->forget();

    $this->get('/t/o/'.$token)->assertOk();

    expect(app(WorkspaceContext::class)->hasWorkspace())->toBeFalse();
});

/*
 | A NYOMKÖVETÉS A TENANT SAJÁT HOSZTJÁN FUT.
 |
 | Nem kényelmi kérdés. Két dolog múlik rajta:
 |  - a link-REPUTÁCIÓ: a domain-wizard az ügyféltől kér egy `email.<domain>` CNAME-et és
 |    verifikálja is; ha a küldés nem használná, minden ügyfél linkje egyetlen közös hosztra
 |    folyna össze;
 |  - a NYÍLT ÁTIRÁNYÍTÓ: a `/t/c/…` az alkalmazás domainjén azon az originen ülne, ami a
 |    bejelentkezett session cookie-ját szolgálja ki — és bármelyik tenant kiválthatna
 |    magának tetszőleges célra mutató linket (küld magának egy levelet, és kiolvassa a
 |    kimenő törzsből).
 */

it('a nyomkövetési link a DOMAIN SAJÁT hosztjára mutat', function () {
    Domain::query()->where('name', 'pelda.hu')->update([
        'tracking_domain' => 'email.pelda.hu',
        'tracking_status' => 'verified',
        'tracking_enabled' => true,
    ]);

    sendTracked();

    expect(sentHtml())->toContain('https://email.pelda.hu/t/o/');
    expect(sentHtml())->toContain('https://email.pelda.hu/t/c/');
});

it('verifikálatlan nyomkövetési domainnél NINCS nyomkövetés', function () {
    // Fail-closed: inkább hiányzik a nyitási statisztika, mint hogy nyílt átirányítót adjunk
    // a saját domainünkön.
    config(['sending.tracking_base_url' => '']);

    Domain::query()->where('name', 'pelda.hu')->update([
        'tracking_domain' => 'email.pelda.hu',
        'tracking_status' => 'pending',
    ]);

    sendTracked();

    expect(sentHtml())->not->toContain('/t/o/');
    expect(sentHtml())->not->toContain('/t/c/');
    expect(sentHtml())->toContain('href="https://ugyfel.example.test/szamla/42"');
});

it('a token MÁS hoszton nem érvényes', function () {
    $message = sendTracked();

    // Ugyanaz a token, de `email.pelda.hu`-ra kiadva: az alkalmazás domainjén (a teszt
    // hosztja `localhost`) nem szabad működnie.
    $idegen = trackingToken((string) $message->uuid, 'cimzett@example.test', 'https://tamado.example.test', 'email.pelda.hu');

    $this->get('/t/c/'.$idegen)->assertNotFound();
    $this->get('/t/o/'.$idegen)->assertOk();

    app(WorkspaceContext::class)->set($this->workspace);

    // …és eseményt sem ír.
    expect(MessageEvent::query()->whereIn('type', ['opened', 'clicked'])->count())->toBe(0);
});

/*
 | AZ ÁTÍRÁS CSAK `<a>` ELEMEKET ÉRINT.
 |
 | A dokumentum egészére engedett `href="…"` csere olyan helyeket is eltalál, ahol a szöveg
 | csak VÉLETLENÜL néz ki linknek — és akkor az ügyfél levele MÁSKÉPP jelenik meg, mint ahogy
 | megírta.
 */

it('a HTML-példakódot mutató tartalmat nem írja át', function () {
    sendTracked([
        'html' => '<html><body><pre>&lt;a href="https://kod.example/x"&gt;</pre>'
            .'<p>Írj ilyet: href="https://tutorial.example/x" a kódba.</p>'
            .'<img alt="kattints: href=\'https://alt.example/x\'" src="https://kep.example/k.png">'
            .'</body></html>',
    ]);

    expect(sentHtml())->toContain('https://kod.example/x');
    expect(sentHtml())->toContain('https://tutorial.example/x');
    expect(sentHtml())->toContain('https://alt.example/x');
    expect(sentHtml())->not->toContain('/t/c/');
});

it('az IDÉZŐJEL NÉLKÜLI href-et is becsomagolja', function () {
    // Kézzel írt HTML-ben és sok sablonmotor kimenetében gyakori; enélkül a
    // klikk-statisztika csendben hiányos lenne rájuk.
    sendTracked(['html' => '<html><body><a href=https://ugyfel.example.test/x>Ide</a></body></html>']);

    expect(sentHtml())->toContain('/t/c/');
    expect(sentHtml())->not->toContain('href=https://ugyfel.example.test/x');
});

it('a MALLEÁLT tokennel sem lehet duplán eseményt írni', function () {
    // A dedup a KANONIKUS igényre kulcsol, nem a token bájtjaira. Token-kulccsal egyetlen
    // elkapott tokenből több ezer érvényes variáns lenne gyártható, mindegyik új eseménnyel —
    // és minden injektált esemény kimenő webhookot is indítana.
    Cache::clear();

    $message = sendTracked();
    $token = trackingToken((string) $message->uuid, 'cimzett@example.test');

    $this->get('/t/o/'.$token)->assertOk();

    // Ugyanaz az igény, más bájtsor (a `Crypt` IV-t használ).
    foreach (range(1, 5) as $ignored) {
        $this->get('/t/o/'.trackingToken((string) $message->uuid, 'cimzett@example.test'))->assertOk();
    }

    app(WorkspaceContext::class)->set($this->workspace);

    expect(MessageEvent::query()->where('type', 'opened')->count())->toBe(1);
});

it('a klikk-válasz NEM tartalmaz injektált fejlécet', function () {
    // A `href` entitás-dekódolás után valódi CR/LF-et hordozhat. Ha az eljutna a `Location`
    // fejlécig, a mögötte álló szöveg külön fejlécsorként jelenne meg.
    sendTracked([
        'html' => '<html><body><a href="https://jo.example/&#13;&#10;X-Injektalt: 1">Ide</a></body></html>',
    ]);

    // A veszélyes linket BE SEM CSOMAGOLJUK — a becsomagolás és a kibontás ugyanazt az
    // ellenőrzést futtatja.
    expect(sentHtml())->not->toContain('/t/c/');
});

it('a nyomkövetés NEM ad session-sütit a levélkliensnek', function () {
    // A pixelt a címzett levélkliense (és a Gmail képproxyja) tölti be: sütit tenni a
    // levelébe semmilyen jogalap nem fedez, és minden betöltés írna egy session-rekordot is.
    $message = sendTracked();

    $response = $this->get('/t/o/'.trackingToken((string) $message->uuid, 'cimzett@example.test'));

    $response->assertOk();

    expect($response->headers->getCookies())->toBe([]);
});
