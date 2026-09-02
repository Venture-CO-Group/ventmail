<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Messages\Services\MimeParser;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\RawMimeMessage;
use App\Modules\Sending\Services\ContentScanner;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | A reviewer + security kör leletei — mindegyikhez a KIHASZNÁLÁS tesztje, nem a javítás
 | leírása. Ha egy javítás visszafordul, itt bukik el.
 */

beforeEach(function () {
    FakeDriver::reset();

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    ['plain' => $this->plain] = ApiKey::issue('CI', ['messages:send', 'templates:write']);
});

function sendApi(array $payload, array $headers = []): TestResponse
{
    return test()->withHeaders(array_merge([
        'Authorization' => 'Bearer '.test()->plain,
        'Idempotency-Key' => 'r-'.bin2hex(random_bytes(6)),
        'Accept' => 'application/json',
    ], $headers))->postJson('/v1/messages', array_merge([
        'from' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'subject' => 'Teszt',
        'html' => '<p>Szia</p>',
    ], $payload));
}

/*
 | BLOKKOLÓ — a MIME-határ a törzsből fejlécet csempészett be.
 */

it('a törzsben lévő CRLFCRLF nem tolja el a fejléc-határt', function () {
    // PoC a reviewból: a fejlécblokk LF-fel zárul, a TÖRZS tartalmaz CRLFCRLF-et.
    // Korábban a `Cc:` sor a törzsből VALÓDI (rejtett) címzetté lépett elő.
    $raw = "From: ok@pelda.hu\nTo: alice@partner.test\nSubject: Szamla\n\n"
        ."Kedves Alice,\nCc: aldozat@target.test\nX-Injected: igen\r\n\r\nvalodi torzs\n";

    $parsed = app(MimeParser::class)->parseHeaders($raw);

    expect($parsed['recipients'])->toBe(['alice@partner.test']);
    expect($parsed['headers'])->not->toHaveKey('X-Injected');
});

it('a törzsben idézett To: sor nem lesz címzett', function () {
    $raw = "From: no-reply@pelda.hu\nSubject: Szamla\n\nKedves Ugyfel,\nTo: aldozat@example.test\r\n\r\ntorzs\n";

    expect(app(MimeParser::class)->parseHeaders($raw)['recipients'])->toBe([]);
});

/*
 | BLOKKOLÓ — a nyers MIME a KÜLDÉS ELŐTT perzisztál.
 */

it('a nyers MIME már a driver hívása előtt eltárolódik', function () {
    $raw = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\nSubject: Nyers\r\n\r\nTorzs\r\n";

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plain,
        'Idempotency-Key' => 'mime-1',
        'Accept' => 'application/json',
    ])->post('/v1/messages.mime', [
        'message' => UploadedFile::fake()->createWithContent('level.eml', $raw),
    ])->assertAccepted();

    $message = Message::firstOrFail();
    $stored = RawMimeMessage::query()->where('message_uuid', $message->uuid)->firstOrFail();

    expect($stored->raw)->toBe($raw);
    // A driver `delivered`-et adott — tehát a küldés PILLANATÁBAN már megvolt a törzs.
    expect($message->status)->toBe('delivered');
    expect(FakeDriver::$sent)->toHaveCount(1);
});

it('ugyanaz az idempotencia-kulcs MÁS nyers MIME-mal 409', function () {
    // A lenyomat korábban nem fedte a MIME tartalmát: a második, teljesen más levél
    // csendben replayt kapott és SOHA nem ment ki.
    $headers = [
        'Authorization' => 'Bearer '.$this->plain,
        'Idempotency-Key' => 'order-123',
        'Accept' => 'application/json',
    ];

    $first = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\nSubject: Egy\r\n\r\nElso\r\n";
    $second = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\nSubject: Egy\r\n\r\nMASODIK\r\n";

    $this->withHeaders($headers)->post('/v1/messages.mime', [
        'message' => UploadedFile::fake()->createWithContent('a.eml', $first),
    ])->assertAccepted();

    $this->withHeaders($headers)->post('/v1/messages.mime', [
        'message' => UploadedFile::fake()->createWithContent('b.eml', $second),
    ])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
});

/*
 | BLOKKOLÓ — a tartalom-kapu megkerülése normalizálással.
 */

dataset('sema-kerulok', [
    'nyers javascript' => ['<a href="javascript:alert(1)">x</a>'],
    'számos entitás' => ['<a href="&#106;avascript:alert(1)">x</a>'],
    'hexa entitás' => ['<a href="&#x6a;avascript:alert(1)">x</a>'],
    'TAB a sémában' => ["<a href=\"java\tscript:alert(1)\">x</a>"],
    'CR a sémában' => ["<a href=\"java\rscript:alert(1)\">x</a>"],
    'vezető kontrollbájt' => ["<a href=\"\x01javascript:alert(1)\">x</a>"],
    'nevesített entitás' => ['<a href="java&Tab;script:alert(1)">x</a>'],
    'CSS url()' => ['<div style="background:url(javascript:alert(1))">x</div>'],
    'eseménykezelő' => ['<img src="https://ok.test/a.png" onerror="alert(1)">'],
    'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
    'meta refresh' => ['<meta http-equiv="refresh" content="0;url=data:text/html,x">'],
    'data: link' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'],
]);

it('a tartalom-kapu a normalizált sémát nézi', function (string $html) {
    expect(app(ContentScanner::class)->firstViolation($html))->not->toBeNull();
})->with('sema-kerulok');

it('a legitim marketing-HTML átmegy', function () {
    // A kapunak nem szabad hasznos tartalmat elutasítania: e-mailben ez a szokásos alak.
    $html = <<<'HTML'
        <style>.btn { background: url("https://cdn.pelda.hu/bg.png"); }</style>
        <a href="https://pelda.hu/akcio?utm_source=hirlevel">Megnézem</a>
        <a href="mailto:info@pelda.hu">Írok</a>
        <a href="tel:+3611234567">Hívom</a>
        <img src="https://cdn.pelda.hu/logo.png" alt="logó">
        <a href="/relativ">Relatív</a>
        <a href="#horgony">Horgony</a>
        HTML;

    expect(app(ContentScanner::class)->firstViolation($html))->toBeNull();
});

it('a VÁLTOZÓBÓL érkező veszélyes séma is elutasítva', function () {
    sendApi([
        'html' => '<a href="%recipient.link%">Kattints</a>',
        'recipient-variables' => json_encode([
            'cimzett@example.test' => ['link' => "java\tscript:alert(1)"],
        ]),
    ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_REQUEST');

    expect(FakeDriver::$sent)->toBeEmpty();
});

it('a NYERS MIME tartalma is átmegy a tartalom-kapun', function () {
    // A szomszédos végponton eddig trükk nélkül kiment, amit a strukturálton tiltunk.
    $raw = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\nSubject: Ajanlat\r\n"
        ."Content-Type: text/html\r\n\r\n<a href=\"javascript:alert(1)\">Kattints</a>\r\n";

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plain,
        'Idempotency-Key' => 'mime-danger',
        'Accept' => 'application/json',
    ])->post('/v1/messages.mime', [
        'message' => UploadedFile::fake()->createWithContent('level.eml', $raw),
    ])->assertStatus(422);

    expect(FakeDriver::$sent)->toBeEmpty();
});

/*
 | BLOKKOLÓ — a versenyt vesztett idempotencia-ág NEM küldhet újra.
 */

it('a versenyt vesztett kérés nem küld másodszor', function () {
    // Az első hívás kiküldi a levelet; a második ugyanazzal a kulccsal a DB unique-jába
    // ütközik. Korábban a vesztes VISSZAÁLLÍTOTTA `accepted`-re a győztes üzenetet és
    // újra dispatch-elt — dupla kézbesítés.
    sendApi([], ['Idempotency-Key' => 'race-1'])->assertAccepted();

    // Szimulált verseny: az „még nem létezik" olvasás után jön létre a sor. A második
    // hívás ugyanazt a kulcsot használja, tehát a replay-ágra fut.
    sendApi([], ['Idempotency-Key' => 'race-1'])->assertAccepted();

    expect(Message::count())->toBe(1);
    expect(FakeDriver::$sent)->toHaveCount(1);
    expect(MessageEvent::where('type', 'delivered')->count())->toBe(1);
});

/*
 | MAGAS — a kirenderelt törzs tényleg a LEVÉLBEN van.
 */

it('a driver a személyre szabott törzset kapja meg', function () {
    sendApi([
        'to' => 'a@example.test,b@example.test',
        'html' => '<p>Kedves %recipient.nev%!</p>',
        'text' => 'Kedves %recipient.nev%!',
        'recipient-variables' => json_encode([
            'a@example.test' => ['nev' => 'Anna'],
            'b@example.test' => ['nev' => 'Bence'],
        ]),
    ])->assertAccepted();

    $bodies = FakeDriver::$sent[0]['bodies'];

    expect($bodies['a@example.test']['html'])->toContain('Anna');
    expect($bodies['b@example.test']['html'])->toContain('Bence');
    // A SZÖVEGES rész is személyre szabott — korábban nyersen ment ki a `%recipient.%`.
    expect($bodies['a@example.test']['text'])->toBe('Kedves Anna!');
    expect($bodies['b@example.test']['text'])->toBe('Kedves Bence!');
});

it('a naplóba továbbra sem kerül törzs', function () {
    sendApi(['html' => '<p>Titkos magic-link: https://pelda.hu/t/abc123</p>'])->assertAccepted();

    $payloads = MessageEvent::pluck('payload')->map(fn ($p) => json_encode($p))->implode(' ');

    expect(str_contains($payloads, 'abc123'))->toBeFalse('a kirenderelt törzs bekerült a naplóba');
    expect(str_contains($payloads, 'body_sha256'))->toBeTrue('a lenyomat hiányzik a naplóból');
});

/*
 | MAGAS — a csatorna a KULCS joga.
 */

it('a kulcshoz kötött csatornán kívül nem lehet küldeni', function () {
    ['plain' => $marketingScope] = ApiKey::issue('marketing-scope', ['messages:send', 'messages:send:marketing']);

    // Scope van, csatorna-jog NINCS (a kulcs alapértelmezése tranzakciós).
    $this->withHeaders([
        'Authorization' => 'Bearer '.$marketingScope,
        'Idempotency-Key' => 'ch-1',
        'Accept' => 'application/json',
    ])->postJson('/v1/messages', [
        'from' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'subject' => 'Hírlevél',
        'html' => '<p>Akció</p>',
        'o:channel' => 'marketing',
    ])->assertStatus(403)->assertJsonPath('error.code', 'CHANNEL_NOT_ALLOWED');
});

it('a marketing kulccsal viszont választható a marketing csatorna', function () {
    ['plain' => $marketing] = ApiKey::issue(
        'marketing',
        ['messages:send', 'messages:send:marketing'],
        channels: ['transactional', 'marketing']
    );

    // A CSATORNA-jogosultság megvan, tehát nem 403 az akadály: innentől a ConsentGate
    // dönt (double opt-in nélkül nincs marketing küldés — golden rule 4).
    $this->withHeaders([
        'Authorization' => 'Bearer '.$marketing,
        'Idempotency-Key' => 'ch-2',
        'Accept' => 'application/json',
    ])->postJson('/v1/messages', [
        'from' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'subject' => 'Hírlevél',
        'html' => '<p>Akció</p>',
        'o:channel' => 'marketing',
    ])->assertStatus(422)->assertJsonPath('error.code', 'NO_CONSENT');
});

/*
 | KÖZEPES — tömb-bemenet és NUL bájt: strukturált hiba, nem 500.
 */

it('a tömb-bemenet strukturált hibát ad, nem 500-at', function () {
    sendApi(['o:channel' => ['marketing']])->assertStatus(400)->assertJsonPath('error.code', 'INVALID_CHANNEL');
    sendApi(['subject' => ['a', 'b']])->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('a NUL bájt strukturált hibát ad, nem 500-at', function () {
    sendApi(['subject' => "Szamla\x00injected"])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_REQUEST');

    expect(Message::count())->toBe(0);
});

/*
 | KÖZEPES — a sablon-út EGY pass.
 */

it('a sablonba behelyettesített érték NEM értékelődik ki másodszor', function () {
    $template = StoredTemplate::create(['name' => 'welcome']);
    $template->versions()->create([
        'body' => '<p>Kedves {{ nev }}!</p>',
        'version' => 1,
        'is_active' => true,
    ]);

    sendApi([
        'html' => null,
        'template' => 'welcome',
        'v:nev' => '%recipient_email%',
    ])->assertAccepted();

    // A behelyettesített érték LITERÁLISAN jelenik meg — nem a címzett e-mail-címe.
    expect(FakeDriver::$sent[0]['bodies']['cimzett@example.test']['html'])
        ->toContain('%recipient_email%');
});

/*
 | KÖZEPES — a rate-limit VISELKEDÉSE (nem a forráskód szövege).
 */

it('a hitelesítetlen özön IP-alapon korlátozva van', function () {
    // Kérésenként MÁS bearer: kulcs-alapú bucketen ez korlátlan lett volna.
    $status = 200;

    foreach (range(1, 30) as $i) {
        $status = $this->withHeaders([
            'Authorization' => 'Bearer nem-letezo-'.$i,
            'Idempotency-Key' => 'f-'.$i,
            'Accept' => 'application/json',
        ])->postJson('/v1/messages', ['from' => 'x@pelda.hu', 'to' => 'a@example.test'])->status();

        if ($status === 429) {
            break;
        }
    }

    expect($status)->toBe(429);
});

it('két különböző kulcs nem eszi meg egymás keretét', function () {
    ['plain' => $masik] = ApiKey::issue('masodik', ['messages:send']);

    foreach (range(1, 20) as $i) {
        sendApi([], ['Idempotency-Key' => 'k1-'.$i])->assertAccepted();
    }

    $this->withHeaders([
        'Authorization' => 'Bearer '.$masik,
        'Idempotency-Key' => 'k2-1',
        'Accept' => 'application/json',
    ])->postJson('/v1/messages', [
        'from' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'subject' => 'Teszt',
        'html' => '<p>Szia</p>',
    ])->assertAccepted();
});

/*
 | KÖZEPES — a nyers MIME retenciója és megváltoztathatatlansága.
 */

it('a nyers MIME lejárati idővel jön létre és a purge törli', function () {
    $raw = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\nSubject: R\r\n\r\nTorzs\r\n";

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plain,
        'Idempotency-Key' => 'ret-1',
        'Accept' => 'application/json',
    ])->post('/v1/messages.mime', [
        'message' => UploadedFile::fake()->createWithContent('level.eml', $raw),
    ])->assertAccepted();

    $stored = RawMimeMessage::firstOrFail();

    expect($stored->expires_at)->not->toBeNull();

    $this->travelTo(now()->addDays((int) config('sending.raw_mime_retention_days') + 1));

    $this->artisan('messages:purge-raw-mime')->assertSuccessful();

    // A parancs elengedi a kontextust (háttérfolyamat) — a lekérdezéshez vissza kell állítani.
    app(WorkspaceContext::class)->set($this->workspace);

    expect(RawMimeMessage::count())->toBe(0);
});

it('a nyers MIME adatbázis-szinten sem írható át', function () {
    $raw = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\nSubject: I\r\n\r\nTorzs\r\n";

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plain,
        'Idempotency-Key' => 'imm-1',
        'Accept' => 'application/json',
    ])->post('/v1/messages.mime', [
        'message' => UploadedFile::fake()->createWithContent('level.eml', $raw),
    ])->assertAccepted();

    // A modell-event megkerülhető volt nyers query-vel — a triggert nem lehet megkerülni.
    expect(fn () => DB::table('raw_mime_messages')->update(['sha256' => str_repeat('0', 64)]))
        ->toThrow(QueryException::class);
});
