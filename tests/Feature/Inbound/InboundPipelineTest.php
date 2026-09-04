<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Contracts\VirusScanner;
use App\Modules\Inbound\Jobs\ForwardInboundToEmail;
use App\Modules\Inbound\Jobs\ForwardInboundToUrl;
use App\Modules\Inbound\Jobs\ParseInboundMessage;
use App\Modules\Inbound\Models\InboundAttachment;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Inbound\Models\InboundRoute;
use App\Modules\Inbound\Services\ArchiveInspector;
use App\Modules\Inbound\Services\InboundMimeParser;
use App\Modules\Inbound\Services\InboundPayload;
use App\Modules\Inbound\Services\MailAuthenticationReader;
use App\Modules\Inbound\Services\RouteEngine;
use App\Modules\Inbound\Services\StrippedTextExtractor;
use App\Modules\Inbound\Testing\FakeRawMailStore;
use App\Modules\Inbound\Testing\FakeVirusScanner;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Contracts\HostResolver;
use App\Modules\Webhooks\Services\WebhookSigner;
use App\Modules\Webhooks\Services\WebhookUrlGuard;
use App\Modules\Webhooks\Testing\FakeHostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 | A4 · 31–33., 35., 38. kritérium — a TELJES bejövő lánc.
 |
 | Fogadás → sandboxolt parse → csatolmány-kezelés → route → továbbítás. A lánc minden szeme
 | külön is mérve van; ITT az a kérdés, hogy EGYBEN is működik-e.
 */

beforeEach(function () {
    $this->store = new FakeRawMailStore;
    $this->scanner = new FakeVirusScanner;

    $this->app->instance(RawMailStore::class, $this->store);
    $this->app->instance(VirusScanner::class, $this->scanner);
    $this->app->instance(HostResolver::class, new FakeHostResolver);

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    $this->domain = Domain::create([
        'name' => 'pelda.hu',
        'status' => 'verified',
        'verified_at' => now(),
        'inbound_mx_status' => 'verified',
    ]);
});

function received(string $raw, array $overrides = []): InboundMessage
{
    $message = InboundMessage::query()->create(array_merge([
        'domain_id' => test()->domain->getKey(),
        'sender' => 'valaki@kuldo.example',
        'recipient' => 'support@pelda.hu',
        'size' => strlen($raw),
        'sha256' => hash('sha256', $raw),
        'status' => 'received',
        'received_at' => now(),
    ], $overrides));

    $key = 'inbound/'.test()->workspace->getKey().'/'.$message->uuid.'.eml';

    test()->store->put($key, $raw);

    $message->forceFill(['storage_key' => $key])->save();

    return $message;
}

function runParse(InboundMessage $message): InboundMessage
{
    (new ParseInboundMessage((int) test()->workspace->getKey(), (string) $message->uuid))->handle(
        app(WorkspaceContext::class),
        app(RawMailStore::class),
        app(InboundMimeParser::class),
        app(StrippedTextExtractor::class),
        app(MailAuthenticationReader::class),
        app(VirusScanner::class),
        app(ArchiveInspector::class),
        app(RouteEngine::class),
    );

    app(WorkspaceContext::class)->set(test()->workspace);

    return $message->fresh();
}

it('a parse kitölti a törzset, a stripped-textet és a hitelesítési eredményt', function () {
    Queue::fake();

    $raw = "Authentication-Results: mx.ventmail.app; spf=pass; dkim=pass; dmarc=pass\r\n"
        ."X-VentMail-Spam-Score: 1.5\r\n"
        ."Subject: Kérdés\r\n"
        ."Message-ID: <abc@kuldo.example>\r\n"
        ."Content-Type: text/plain; charset=UTF-8\r\n"
        ."\r\n"
        ."Köszönöm, megnéztem.\r\n\r\n2026. szeptember 4. Anna <anna@x.test> ezt írta:\r\n> Szia\r\n";

    $message = runParse(received($raw));

    expect($message->status)->toBe('routed');
    expect($message->subject)->toBe('Kérdés');
    expect($message->body_plain)->toContain('Köszönöm');
    expect($message->stripped_text)->toBe('Köszönöm, megnéztem.');
    expect($message->spf_result)->toBe('pass');
    expect($message->dkim_result)->toBe('pass');
    expect($message->dmarc_result)->toBe('pass');
    expect($message->spam_score)->toBe(1.5);
});

it('az IDEGEN Authentication-Results fejlécet nem hiszi el', function () {
    // A `dkim=pass`-t bárki beírhatja a saját levelébe. Csak a SAJÁT authserv-id-nkkal
    // kezdődő fejlécet olvassuk sajátunkként.
    Queue::fake();

    $raw = "Authentication-Results: gmail.com; spf=pass; dkim=pass\r\nSubject: Hamis\r\n\r\ntörzs";

    $message = runParse(received($raw));

    expect($message->spf_result)->toBeNull();
    expect($message->dkim_result)->toBeNull();
});

it('a csatolmányt eltárolja és megszkenneli', function () {
    Queue::fake();

    $raw = "Content-Type: multipart/mixed; boundary=\"H\"\r\n\r\n"
        ."--H\r\nContent-Type: text/plain\r\n\r\nSzöveg\r\n"
        ."--H\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"szamla.pdf\"\r\n"
        ."Content-Transfer-Encoding: base64\r\n\r\n".base64_encode('PDF')."\r\n--H--\r\n";

    $message = runParse(received($raw));

    $attachment = InboundAttachment::query()->firstOrFail();

    expect($attachment->filename)->toBe('szamla.pdf');
    expect($attachment->scan_result)->toBe('clean');
    expect($attachment->downloadable())->toBeTrue();
    expect($this->store->get((string) $attachment->storage_key))->toBe('PDF');
});

it('a FERTŐZÖTT csatolmány karanténba megy, és nem tölthető le', function () {
    Queue::fake();

    $raw = "Content-Type: multipart/mixed; boundary=\"H\"\r\n\r\n"
        ."--H\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=\"virus.exe\"\r\n"
        ."\r\n".FakeVirusScanner::EICAR."\r\n--H--\r\n";

    runParse(received($raw));

    $attachment = InboundAttachment::query()->firstOrFail();

    expect($attachment->scan_result)->toBe('infected');
    expect($attachment->downloadable())->toBeFalse();
    // A tartalom MEGVAN — az ügyfélnek látnia kell, mi érkezett —, de karantén-prefixen.
    expect($attachment->storage_key)->toContain('quarantine');
});

it('a szkenner KIESÉSE nem jelent tisztát', function () {
    // Fail-open esetén a szkennerünk kiesésének idején MI terjesztenénk a kártevőt.
    Queue::fake();

    $this->scanner->available = false;

    $raw = "Content-Type: multipart/mixed; boundary=\"H\"\r\n\r\n"
        ."--H\r\nContent-Disposition: attachment; filename=\"a.bin\"\r\n\r\nakarmi\r\n--H--\r\n";

    runParse(received($raw));

    $attachment = InboundAttachment::query()->firstOrFail();

    expect($attachment->scan_result)->toBe('unavailable');
    expect($attachment->downloadable())->toBeFalse();
});

it('a TÚL NAGY csatolmány tartalmát el sem tesszük', function () {
    Queue::fake();

    config(['inbound.attachments.max_bytes' => 10]);

    $raw = "Content-Type: multipart/mixed; boundary=\"H\"\r\n\r\n"
        ."--H\r\nContent-Disposition: attachment; filename=\"nagy.bin\"\r\n\r\n".str_repeat('x', 500)."\r\n--H--\r\n";

    runParse(received($raw));

    $attachment = InboundAttachment::query()->firstOrFail();

    expect($attachment->scan_result)->toBe('rejected');
    expect($attachment->storage_key)->toBeNull();
    // A METAADAT megmarad: az ügyfél lássa, mi érkezett és miért nincs meg.
    expect($attachment->filename)->toBe('nagy.bin');
});

it('a ZIP-BOMBÁT elutasítja — szkennelés ELŐTT', function () {
    Queue::fake();

    config(['inbound.attachments.max_compression_ratio' => 100]);

    $bomba = "PK\x03\x04".str_repeat("\x00", 18).pack('V', 1_073_741_824).str_repeat("\x00", 4).str_repeat('x', 50);

    $raw = "Content-Type: multipart/mixed; boundary=\"H\"\r\n\r\n"
        ."--H\r\nContent-Disposition: attachment; filename=\"bomba.zip\"\r\n"
        ."Content-Transfer-Encoding: base64\r\n\r\n".base64_encode($bomba)."\r\n--H--\r\n";

    runParse(received($raw));

    $attachment = InboundAttachment::query()->firstOrFail();

    expect($attachment->scan_result)->toBe('rejected');
    // A DEKLARÁLT 1 GB az ABSZOLÚT plafonba ütközik. Az arány-korlát a másik zár: az
    // önmagában nem elég, mert egy 10 GB-ra bomló, 200 MB-os archívum aránya „csak" 50.
    expect($attachment->scan_detail)->toContain('kicsomagolt méret');
    expect($attachment->storage_key)->toBeNull();
});

it('a ZIP-BOMBÁT az ARÁNY alapján is elutasítja', function () {
    Queue::fake();

    config([
        'inbound.attachments.max_compression_ratio' => 20,
        'inbound.attachments.max_uncompressed_bytes' => 1_073_741_824,
    ]);

    // 10 MB deklarált kicsomagolt méret ~200 bájtból: az abszolút plafon ALATT van, az arány
    // viszont 50 000:1.
    $bomba = "PK\x03\x04".str_repeat("\x00", 18).pack('V', 10_485_760).str_repeat("\x00", 4).str_repeat('x', 50);

    $raw = "Content-Type: multipart/mixed; boundary=\"H\"\r\n\r\n"
        ."--H\r\nContent-Disposition: attachment; filename=\"bomba.zip\"\r\n"
        ."Content-Transfer-Encoding: base64\r\n\r\n".base64_encode($bomba)."\r\n--H--\r\n";

    runParse(received($raw));

    $attachment = InboundAttachment::query()->firstOrFail();

    expect($attachment->scan_result)->toBe('rejected');
    expect($attachment->scan_detail)->toContain('arány');
});

it('a MALFORMÁLT MIME nem dönti el a workert — az üzenet failed lesz, nem tűnik el', function () {
    Queue::fake();

    // Nyers bináris szemét: a parse nem dobhat úgy, hogy a job elhal.
    $message = runParse(received("\x00\x01\xFF\xFE nem MIME"));

    expect($message->status)->toBeIn(['routed', 'parsed', 'failed']);
    expect(InboundMessage::query()->count())->toBe(1);
});

it('a HIÁNYZÓ nyers levél nem kivétel, hanem failed állapot', function () {
    Queue::fake();

    $message = received('törzs');
    $this->store->delete((string) $message->storage_key);

    expect(runParse($message)->status)->toBe('failed');
});

/*
 | forward(url) — ALÁÍRT POST, SSRF-védelemmel (31. kritérium).
 */

it('a forward(url) ALÁÍRT payloadot POSTol', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $message = runParse(received("Subject: Kérdés\r\n\r\nSzia"));

    (new ForwardInboundToUrl((int) $this->workspace->getKey(), (string) $message->uuid, 'https://hook.example.test/mail'))
        ->handle(
            app(WorkspaceContext::class),
            app(InboundPayload::class),
            app(WebhookSigner::class),
            app(WebhookUrlGuard::class),
        );

    Http::assertSent(function ($request) {
        $payload = $request->body();
        $timestamp = (int) $request->header('X-VentMail-Timestamp')[0];
        $signature = $request->header('X-VentMail-Signature')[0];

        $secret = hash_hmac('sha256', 'inbound-forward:'.test()->workspace->uuid, (string) config('app.key'));

        // Ugyanaz az aláírás-séma, mint a webhookoké: az integrátornak nem kell két
        // különböző ellenőrzőt írnia.
        expect(app(WebhookSigner::class)->verify($secret, $payload, $timestamp, $signature))->toBeTrue();

        $data = json_decode($payload, true);

        expect($data['recipient'])->toBe('support@pelda.hu');
        expect($data['subject'])->toBe('Kérdés');
        expect($data)->toHaveKey('stripped-text');
        expect($data)->toHaveKey('authentication');

        return true;
    });
});

it('a forward(url) NEM megy belső címre', function () {
    // A route-szabály `forward("https://169.254.169.254/…")`-je pontosan olyan veszélyes,
    // mint egy webhook-végpont ugyanarra a címre.
    Http::fake();

    $this->app->instance(HostResolver::class, new FakeHostResolver(['metadata.example' => ['169.254.169.254']]));

    $message = runParse(received("Subject: x\r\n\r\ny"));

    (new ForwardInboundToUrl((int) $this->workspace->getKey(), (string) $message->uuid, 'https://metadata.example/latest/'))
        ->handle(
            app(WorkspaceContext::class),
            app(InboundPayload::class),
            app(WebhookSigner::class),
            app(WebhookUrlGuard::class),
        );

    Http::assertNothingSent();
});

it('a payloadban a FERTŐZÖTT csatolmányhoz nincs letöltési URL', function () {
    Queue::fake();

    $raw = "Content-Type: multipart/mixed; boundary=\"H\"\r\n\r\n"
        ."--H\r\nContent-Disposition: attachment; filename=\"virus.exe\"\r\n\r\n".FakeVirusScanner::EICAR."\r\n--H--\r\n";

    $message = runParse(received($raw));

    $payload = app(InboundPayload::class)->for($message->fresh()->load('attachments'));

    expect($payload['attachments'][0]['scan'])->toBe('infected');
    expect($payload['attachments'][0]['url'])->toBeNull();
    // A TÉNY viszont ott van: az ügyfélnek látnia kell, mi érkezett.
    expect($payload['attachments'][0]['name'])->toBe('virus.exe');
});

/*
 | forward(email) — a PreSendGate-en át (32. kritérium).
 */

it('a forward(email) a PreSendGate-en megy át', function () {
    $message = runParse(received("Subject: Kérdés\r\n\r\nSzia"));

    (new ForwardInboundToEmail((int) $this->workspace->getKey(), (string) $message->uuid, 'ops@pelda.hu'))
        ->handle(app(WorkspaceContext::class), app(SendMessage::class));

    app(WorkspaceContext::class)->set($this->workspace);

    $sent = Message::query()->where('subject', 'Kérdés')->firstOrFail();

    // A gate LEFUTOTT: a döntés nyoma ott van az üzenet trace-én.
    expect($sent->options['gate_trace'])->toBeArray()->not->toBeEmpty();
    // A FELADÓ MI vagyunk — nem az eredeti küldő. Az ő címéről a mi IP-nkről küldve az SPF
    // azonnal megbukna.
    expect($sent->from_email)->toBe('noreply@pelda.hu');
    // …az eredeti feladó viszont a `Reply-To`-ban van: a válasz oda megy.
    expect($sent->headers['Reply-To'])->toBe('valaki@kuldo.example');
    expect($sent->channel)->toBe('transactional');
});

it('a forward(email) a SUPPRESSION-listán megáll', function () {
    // Ha a továbbításnak lenne „belső" útja a gate mellett, egy route-szabállyal bárki
    // küldhetne suppression-listás címre.
    Suppression::create(['email' => 'ops@pelda.hu', 'reason' => 'complaint']);

    $message = runParse(received("Subject: Kérdés\r\n\r\nSzia"));

    (new ForwardInboundToEmail((int) $this->workspace->getKey(), (string) $message->uuid, 'ops@pelda.hu'))
        ->handle(app(WorkspaceContext::class), app(SendMessage::class));

    app(WorkspaceContext::class)->set($this->workspace);

    $sent = Message::query()->where('subject', 'Kérdés')->firstOrFail();

    expect($sent->status)->toBe('rejected');
    expect($sent->reason)->toBe('SUPPRESSED');
});

it('a KÉTSZER futó továbbítás nem küld kétszer', function () {
    $message = runParse(received("Subject: Kérdés\r\n\r\nSzia"));

    foreach (range(1, 2) as $ignored) {
        (new ForwardInboundToEmail((int) $this->workspace->getKey(), (string) $message->uuid, 'ops@pelda.hu'))
            ->handle(app(WorkspaceContext::class), app(SendMessage::class));

        app(WorkspaceContext::class)->set($this->workspace);
    }

    expect(Message::query()->where('subject', 'Kérdés')->count())->toBe(1);
});

/*
 | TENANT-IZOLÁCIÓ (37. kritérium).
 */

it('IDEGEN workspace levele nem látszik', function () {
    Queue::fake();

    runParse(received("Subject: Saját\r\n\r\ntörzs"));

    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);

    expect(InboundMessage::query()->count())->toBe(0);
    expect(InboundAttachment::query()->count())->toBe(0);
    expect(InboundRoute::query()->count())->toBe(0);
});

it('a bejövő üzenetnek RETENCIÓJA van — idegen tartalom', function () {
    Queue::fake();

    config(['inbound.retention_days' => 3]);

    $message = received('törzs');

    expect($message->expires_at)->not->toBeNull();
    expect($message->expires_at->diffInDays(now()))->toBeLessThanOrEqual(3);
});
