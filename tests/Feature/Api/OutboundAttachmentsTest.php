<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Data\DispatchResult;
use App\Modules\Sending\Data\DriverCapabilities;
use App\Modules\Sending\Data\DriverHealth;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Drivers\PostalDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\MessageAttachment;
use App\Modules\Sending\Services\MessageDispatcher;
use App\Modules\Sending\Services\RetryPolicy;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\DkimVerifier;

uses(RefreshDatabase::class);

/*
 | H123 (Jersey Magic) — csatolmány a `POST /v1/messages`-en.
 |
 | A kérés JSON-ban, base64 tartalommal küldi a csatolmányt. A szerződés BŐVÍTÉS (golden
 | rule 7): csatolmány nélkül minden kérés pontosan úgy viselkedik, mint eddig, a multipart
 | `attachment` mező pedig továbbra is `400 UNSUPPORTED_ATTACHMENT`.
 |
 | A csatolmány PII (egy fan fotója): a bájtok csak a kiküldésig / az újrapróbálás végéig
 | élnek, titkosítva, és soha nem kerülnek naplóba, eseménybe vagy a `messages` sorba.
 */

/** Minimális, a mágikus bájtok szerint valódi fájlok — a tartalom-szippantás ezeket nézi. */
function jpegBytes(int $size = 2048): string
{
    return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat("\xAB", max(0, $size - 13))."\xFF\xD9";
}

function pngBytes(): string
{
    return "\x89PNG\r\n\x1a\n".str_repeat("\x00", 64);
}

function pdfBytes(): string
{
    return "%PDF-1.7\n".str_repeat('x', 64)."\n%%EOF\n";
}

function attachment(string $bytes, string $filename = 'kep.jpg', ?string $type = 'image/jpeg'): array
{
    return array_filter([
        'filename' => $filename,
        'content_type' => $type,
        'content' => base64_encode($bytes),
    ], fn ($value) => $value !== null);
}

function sendWith(mixed $attachments, array $overrides = [], ?string $key = null): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->plain,
        'Idempotency-Key' => $key ?? 'att-'.bin2hex(random_bytes(6)),
        'Accept' => 'application/json',
    ])->postJson('/v1/messages', array_merge([
        'from' => 'Jersey Magic <no-reply@pelda.hu>',
        'to' => 'fan@example.test',
        'subject' => 'Itt a képed',
        'html' => '<p>Szia</p>',
        'attachments' => $attachments,
    ], $overrides));
}

beforeEach(function () {
    FakeDriver::reset();

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    $this->domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    ['plain' => $this->plain] = ApiKey::issue('JM', ['messages:send', 'templates:write']);
});

// --- A boldog út -------------------------------------------------------------------------

it('JPEG-csatolmánnyal 202, és a driver megkapja a fájlt', function () {
    $bytes = jpegBytes();

    sendWith([attachment($bytes, 'jersey-magic.jpg')])
        ->assertAccepted()
        ->assertJsonPath('status', 'delivered');

    expect(FakeDriver::$sent)->toHaveCount(1)
        ->and(FakeDriver::$sent[0]['attachments'])->toBe([[
            'filename' => 'jersey-magic.jpg',
            'content_type' => 'image/jpeg',
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
        ]]);
});

/** A PDF alapból KI van kapcsolva (S3: amíg nincs fail-closed víruskereső) — itt bekapcsoljuk. */
function enablePdf(): void
{
    config(['sending.attachments.allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf']]);
}

it('PNG, GIF, WebP és (bekapcsolva) PDF engedett', function (string $bytes, string $name, string $type) {
    enablePdf();

    sendWith([attachment($bytes, $name, $type)])->assertAccepted();

    expect(FakeDriver::$sent[0]['attachments'][0]['content_type'])->toBe($type);
})->with([
    'png' => [pngBytes(), 'a.png', 'image/png'],
    'gif' => ['GIF89a'.str_repeat("\x00", 32), 'a.gif', 'image/gif'],
    'webp' => ['RIFF'.pack('V', 40).'WEBPVP8 '.str_repeat("\x00", 32), 'a.webp', 'image/webp'],
    'pdf' => [pdfBytes(), 'szamla.pdf', 'application/pdf'],
]);

it('a content_type elhagyható: a tartalomból állapítjuk meg', function () {
    sendWith([attachment(pngBytes(), 'kep.png', null)])->assertAccepted();

    expect(FakeDriver::$sent[0]['attachments'][0]['content_type'])->toBe('image/png');
});

it('az image/jpg alias image/jpeg-ként megy ki, a paraméterek levágva', function () {
    sendWith([attachment(jpegBytes(), 'kep.jpg', 'Image/JPG; name="x"')])->assertAccepted();

    expect(FakeDriver::$sent[0]['attachments'][0]['content_type'])->toBe('image/jpeg');
});

it('több csatolmány a sorrendjük szerint megy ki', function () {
    enablePdf();

    sendWith([attachment(jpegBytes(), 'elso.jpg'), attachment(pdfBytes(), 'masodik.pdf', 'application/pdf')])
        ->assertAccepted();

    expect(array_column(FakeDriver::$sent[0]['attachments'], 'filename'))->toBe(['elso.jpg', 'masodik.pdf']);
});

it('a DKIM-aláírt MIME tartalmazza a csatolmányt, és az aláírás érvényes', function () {
    $this->domain->forceFill([
        'dkim_selector' => 'vm202609',
        'dkim_private_key' => file_get_contents(base_path('tests/Fixtures/dkim-test-key.pem')),
        'dkim_public_key' => file_get_contents(base_path('tests/Fixtures/dkim-test-key.pub.pem')),
    ])->save();

    $bytes = jpegBytes(4096);

    sendWith([attachment($bytes, 'jersey-magic.jpg')])->assertAccepted();

    $mime = (string) FakeDriver::$sent[0]['bodies']['fan@example.test']['mime'];

    expect($mime)->toContain('Content-Type: image/jpeg; name=jersey-magic.jpg')
        ->and($mime)->toContain('Content-Disposition: attachment; name=jersey-magic.jpg;')
        ->and($mime)->toContain(chunk_split(base64_encode($bytes), 76, "\r\n"))
        ->and(DkimVerifier::verify($mime, file_get_contents(base_path('tests/Fixtures/dkim-test-key.pub.pem'))))
        ->toBeTrue('a csatolmányos levél DKIM-aláírása nem verifikálható');
});

it('a tárolt sablonos küldés is viszi a csatolmányt', function () {
    $template = StoredTemplate::create(['name' => 'jerseymagic.e1-hu']);
    StoredTemplateVersion::create([
        'stored_template_id' => $template->getKey(), 'version' => 1,
        'body' => '<p>Kész a képed, {{ name }}!</p>', 'subject' => 'Kész', 'is_active' => true,
    ]);

    sendWith([attachment(jpegBytes())], ['template' => 'jerseymagic.e1-hu', 'html' => null, 'v:name' => 'Anna'])
        ->assertAccepted();

    expect(FakeDriver::$sent[0]['attachments'])->toHaveCount(1)
        ->and(FakeDriver::$sent[0]['bodies']['fan@example.test']['html'])->toContain('Anna');
});

it('a PostalDriver a nem aláírt úton base64-ként adja át a csatolmányt', function () {
    config([
        'sending.driver' => 'postal',
        'sending.postal.endpoint' => 'https://postal.test',
        'sending.postal.api_key' => 'kulcs',
    ]);
    app()->forgetInstance(SendingDriver::class);
    app()->bind(SendingDriver::class, PostalDriver::class);

    Http::fake(['https://postal.test/*' => Http::response(['data' => ['message_id' => 'p-1']])]);

    $bytes = jpegBytes();
    sendWith([attachment($bytes, 'kep.jpg')])->assertAccepted();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/send/message')
        && $request['attachments'] === [[
            'name' => 'kep.jpg',
            'content_type' => 'image/jpeg',
            'data' => base64_encode($bytes),
        ]]);
});

// --- Visszafelé kompatibilitás -----------------------------------------------------------

it('csatolmány nélkül a viselkedés változatlan', function () {
    sendWith([], ['attachments' => null])->assertAccepted();

    expect(FakeDriver::$sent[0]['attachments'])->toBe([])
        ->and(Message::query()->first()->options)->not->toHaveKey('attachments');
});

it('az üres attachments lista nem hiba', function () {
    sendWith([])->assertAccepted();

    expect(MessageAttachment::query()->count())->toBe(0);
});

it('a multipart attachment fájl továbbra is UNSUPPORTED_ATTACHMENT', function () {
    test()->withHeaders([
        'Authorization' => 'Bearer '.$this->plain,
        'Idempotency-Key' => 'mp-1',
        'Accept' => 'application/json',
    ])->post('/v1/messages', [
        'from' => 'no-reply@pelda.hu',
        'to' => 'fan@example.test',
        'html' => '<p>x</p>',
        'attachment' => [UploadedFile::fake()->create('szamla.pdf', 10)],
    ])->assertStatus(400)->assertJsonPath('error.code', 'UNSUPPORTED_ATTACHMENT');
});

// --- Ellenőrzések: típus, hamisítás, méret, darabszám, formátum -------------------------

it('nem engedett típus: UNSUPPORTED_ATTACHMENT_TYPE', function (string $bytes, string $name, ?string $type) {
    sendWith([attachment($bytes, $name, $type)])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'UNSUPPORTED_ATTACHMENT_TYPE');

    expect(Message::query()->count())->toBe(0);
})->with([
    'html' => ['<html><script>alert(1)</script></html>', 'a.html', 'text/html'],
    'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>', 'a.svg', 'image/svg+xml'],
    'exe' => ["MZ\x90\x00".str_repeat("\x00", 60), 'a.exe', 'application/octet-stream'],
    'zip' => ["PK\x03\x04".str_repeat("\x00", 60), 'a.zip', 'application/zip'],
    'ismeretlen tartalom, típus nélkül' => ["MZ\x90\x00".str_repeat("\x00", 60), 'a.jpg', null],
]);

it('a deklarált típus és a tartalom eltérése: ATTACHMENT_TYPE_MISMATCH', function (string $bytes, string $type) {
    enablePdf();

    sendWith([attachment($bytes, 'kep.jpg', $type)])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'ATTACHMENT_TYPE_MISMATCH');
})->with([
    'HTML jpeg-nek álcázva' => ['<html><script>alert(1)</script></html>', 'image/jpeg'],
    'futtatható jpeg-nek álcázva' => ["MZ\x90\x00".str_repeat("\x00", 60), 'image/jpeg'],
    'PDF png-nek álcázva' => [pdfBytes(), 'image/png'],
    'JPEG pdf-nek álcázva' => [jpegBytes(), 'application/pdf'],
]);

it('a darabonkénti méretkorlát: ATTACHMENT_TOO_LARGE', function () {
    config(['sending.attachments.max_file_bytes' => 1000]);

    sendWith([attachment(jpegBytes(1001))])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'ATTACHMENT_TOO_LARGE');

    sendWith([attachment(jpegBytes(1000))])->assertAccepted();
});

it('az összesített méretkorlát: ATTACHMENT_TOO_LARGE', function () {
    config(['sending.attachments.max_file_bytes' => 1000, 'sending.attachments.max_total_bytes' => 1500]);

    sendWith([attachment(jpegBytes(800)), attachment(jpegBytes(800))])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'ATTACHMENT_TOO_LARGE');
});

it('a méretet a DEKÓDOLÁS ELŐTT becsüljük: a túl hosszú base64 nem dekódolódik', function () {
    config(['sending.attachments.max_file_bytes' => 1000]);

    // Érvénytelen base64 (a `!` nincs az ábécében), de a hossza már önmagában túl nagy —
    // a válasz a méret-hiba, tehát a dekódolás el sem indult.
    sendWith([['filename' => 'a.jpg', 'content' => str_repeat('!', 4000)]])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'ATTACHMENT_TOO_LARGE');
});

it('a darabszám-korlát: TOO_MANY_ATTACHMENTS', function () {
    config(['sending.attachments.max_count' => 2]);

    sendWith(array_fill(0, 3, attachment(jpegBytes(100))))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'TOO_MANY_ATTACHMENTS');
});

it('batch-ben a csatolmány-bájt × címzett szorzat korlátos', function () {
    // Címzettenként épül a MIME: ezer címzett × 10 MB a worker memóriáját vinné el.
    config(['sending.attachments.max_batch_bytes' => 5000]);

    sendWith([attachment(jpegBytes(2000))], ['to' => ['a@example.test', 'b@example.test', 'c@example.test']])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'ATTACHMENT_TOO_LARGE');

    sendWith([attachment(jpegBytes(2000))], ['to' => ['a@example.test', 'b@example.test']])
        ->assertAccepted();
});

it('formai hibák: INVALID_ATTACHMENT', function (mixed $attachments) {
    sendWith($attachments)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'INVALID_ATTACHMENT');
})->with([
    'nem lista' => [['filename' => 'a.jpg', 'content' => 'AAAA']],
    'skalár' => ['szoveg'],
    'elem nem objektum' => [['szoveg']],
    'hiányzó tartalom' => [[['filename' => 'a.jpg']]],
    'üres tartalom' => [[['filename' => 'a.jpg', 'content' => '']]],
    'érvénytelen base64' => [[['filename' => 'a.jpg', 'content' => 'nem*base64!']]],
    'hiányzó fájlnév' => [[['content' => base64_encode(jpegBytes())]]],
    'fájlnév tömb' => [[['filename' => ['a'], 'content' => base64_encode(jpegBytes())]]],
    'content_type tömb' => [[['filename' => 'a.jpg', 'content_type' => ['image/jpeg'], 'content' => base64_encode(jpegBytes())]]],
]);

it('a soros base64 (MIME-stílusú sortörésekkel) elfogadott', function () {
    $bytes = jpegBytes(500);

    sendWith([['filename' => 'a.jpg', 'content_type' => 'image/jpeg', 'content' => chunk_split(base64_encode($bytes), 76, "\r\n")]])
        ->assertAccepted();

    expect(FakeDriver::$sent[0]['attachments'][0]['sha256'])->toBe(hash('sha256', $bytes));
});

// --- Fájlnév-tisztítás -------------------------------------------------------------------

it('a fájlnév tisztítva megy ki', function (string $given, string $expected) {
    sendWith([attachment(jpegBytes(), $given)])->assertAccepted();

    expect(FakeDriver::$sent[0]['attachments'][0]['filename'])->toBe($expected);
})->with([
    'útvonal' => ['../../etc/passwd.jpg', 'passwd.jpg'],
    'windows útvonal' => ['C:\\Users\\fan\\kep.jpg', 'kep.jpg'],
    'CRLF fejléc-injekció' => ["kep\r\nBcc: x@evil.test.jpg", 'kepBcc x@evil.test.jpg'],
    'NUL és vezérlőkarakter' => ["ke\0p\x07.jpg", 'kep.jpg'],
    'idézőjel és tiltott jelek' => ['"a<b>|c?*:".jpg', 'abc.jpg'],
    'rossz kiterjesztés' => ['kep.exe', 'kep.exe.jpg'],
    'nincs kiterjesztés' => ['kep', 'kep.jpg'],
    'jpeg kiterjesztés marad' => ['kep.JPEG', 'kep.JPEG'],
    'csak pontok' => ['...', 'attachment.jpg'],
    'üres a tisztítás után' => ["\x07\x08", 'attachment.jpg'],
    'ékezet marad' => ['árvíztűrő.jpg', 'árvíztűrő.jpg'],
    'RTL override' => ["kep\u{202E}gpj.exe", 'kepgpj.exe.jpg'],
]);

it('a túl hosszú fájlnév rövidül, a kiterjesztés megmarad', function () {
    sendWith([attachment(jpegBytes(), str_repeat('a', 300).'.jpg')])->assertAccepted();

    $name = FakeDriver::$sent[0]['attachments'][0]['filename'];

    expect(mb_strlen($name))->toBe(100)
        ->and($name)->toEndWith('.jpg');
});

it('a nem UTF-8 fájlnév: INVALID_ATTACHMENT', function () {
    // JSON-ban érvénytelen UTF-8 nem is érkezhet; form-kódolva igen.
    test()->withHeaders([
        'Authorization' => 'Bearer '.$this->plain,
        'Idempotency-Key' => 'utf-1',
        'Accept' => 'application/json',
    ])->post('/v1/messages', [
        'from' => 'no-reply@pelda.hu',
        'to' => 'fan@example.test',
        'html' => '<p>x</p>',
        'attachments' => [['filename' => "k\xE9p.jpg", 'content' => base64_encode(jpegBytes())]],
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'INVALID_ATTACHMENT');
});

// --- Idempotencia ------------------------------------------------------------------------

it('ugyanaz a kulcs ugyanazzal a csatolmánnyal: replay, nincs második küldés', function () {
    $bytes = jpegBytes();

    $first = sendWith([attachment($bytes)], [], 'jm-e1-1');
    $second = sendWith([attachment($bytes)], [], 'jm-e1-1');

    $first->assertAccepted();
    $second->assertAccepted();
    expect($second->json('id'))->toBe($first->json('id'))
        ->and(FakeDriver::$sent)->toHaveCount(1);
});

it('ugyanaz a kulcs MÁS csatolmánnyal: 409', function () {
    sendWith([attachment(jpegBytes(100))], [], 'jm-e1-2')->assertAccepted();

    sendWith([attachment(jpegBytes(200))], [], 'jm-e1-2')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
});

// --- Megőrzés és PII ---------------------------------------------------------------------

it('a kiküldés után a bájtok törlődnek, csak a metaadat marad a soron', function () {
    $bytes = jpegBytes();

    sendWith([attachment($bytes, 'fan-anna.jpg')])->assertAccepted();

    $message = Message::query()->first();

    expect(MessageAttachment::query()->count())->toBe(0)
        // A trace-hez: típus + méret. Fájlnév NINCS — az PII lehet (`kiss-anna.jpg`).
        ->and($message->options['attachments'])->toEqual([['content_type' => 'image/jpeg', 'size' => strlen($bytes)]]);
});

it('a csatolmány tartalma SEHOL nem kerül a messages sorba és az eseményekbe', function () {
    $bytes = jpegBytes();
    $encoded = base64_encode($bytes);

    sendWith([attachment($bytes, 'fan-anna.jpg')])->assertAccepted();

    $row = (array) DB::table('messages')->first();
    $events = DB::table('message_events')->get()->map(fn ($event) => json_encode($event))->implode("\n");

    expect(json_encode($row))->not->toContain(substr($encoded, 0, 40))
        ->and(json_encode($row))->not->toContain('fan-anna')
        ->and($events)->not->toContain(substr($encoded, 0, 40))
        ->and($events)->not->toContain('fan-anna');
});

it('a kapu által elutasított levél bájtjai nem maradnak meg', function () {
    $this->domain->forceFill(['status' => 'pending', 'verified_at' => null])->save();

    sendWith([attachment(jpegBytes())])->assertStatus(422);

    expect(MessageAttachment::query()->count())->toBe(0);
});

it('testmode-ban a bájtok el sem tárolódnak', function () {
    sendWith([attachment(jpegBytes())], ['o:testmode' => 'yes'])->assertAccepted();

    expect(MessageAttachment::query()->count())->toBe(0)
        ->and(FakeDriver::$sent)->toBe([]);
});

it('ütemezett küldésnél a bájtok titkosítva várnak, a lejárat az időpont + a retenció', function () {
    config(['sending.attachments.retention_hours' => 48]);
    $when = now()->addHours(20)->startOfMinute();

    $bytes = jpegBytes();
    sendWith([attachment($bytes)], ['o:deliverytime' => $when->toRfc2822String()])
        ->assertAccepted()
        ->assertJsonPath('status', 'scheduled');

    $stored = MessageAttachment::query()->first();
    $raw = (string) DB::table('message_attachments')->value('content');

    expect($stored->content)->toBe($bytes)
        ->and($stored->expires_at->equalTo($when->copy()->addHours(48)))->toBeTrue()
        // Titkosítva nyugszik: sem a nyers bájtok, sem a base64-ük nincs a sorban.
        ->and($raw)->not->toContain(substr(base64_encode($bytes), 0, 40))
        ->and($raw)->not->toContain("\xFF\xD8\xFF");
});

it('átmeneti hibánál a bájtok megmaradnak az újrapróbáláshoz, végleges hibánál törlődnek', function () {
    $driver = new class implements SendingDriver
    {
        public bool $fail = true;

        public function send(Message $message): DispatchResult
        {
            return $this->fail
                ? DispatchResult::failed('Postal hiba: 503', 451, 'try later')
                : DispatchResult::accepted('x');
        }

        public function capabilities(): DriverCapabilities
        {
            return new DriverCapabilities(name: 'flaky');
        }

        public function health(): DriverHealth
        {
            return new DriverHealth(healthy: true, detail: 'ok');
        }
    };
    app()->instance(SendingDriver::class, $driver);

    sendWith([attachment(jpegBytes())])->assertAccepted();

    $message = Message::query()->first();
    expect($message->status)->toBe('deferred')
        ->and(MessageAttachment::query()->count())->toBe(1);

    // A keret kimerül: `failed` — a bájtokra nincs többé szükség.
    $message->forceFill(['status' => 'failed'])->save();

    expect(MessageAttachment::query()->count())->toBe(0);
});

it('a hiányzó (már törölt) csatolmánnyal a levél NEM megy ki csonkán', function () {
    $driver = new class implements SendingDriver
    {
        public function send(Message $message): DispatchResult
        {
            return DispatchResult::failed('Postal hiba: 503', 451, 'try later');
        }

        public function capabilities(): DriverCapabilities
        {
            return new DriverCapabilities(name: 'flaky');
        }

        public function health(): DriverHealth
        {
            return new DriverHealth(healthy: true, detail: 'ok');
        }
    };
    app()->instance(SendingDriver::class, $driver);

    sendWith([attachment(jpegBytes())])->assertAccepted();

    $message = Message::query()->first();
    MessageAttachment::query()->delete();

    expect(fn () => $message->outboundAttachments())->toThrow(RuntimeException::class);

    // A dispatcher újrapróbálása: a driver NEM kapja meg a levelet, az állapot `deferred`.
    app()->instance(SendingDriver::class, new FakeDriver);
    app()->forgetInstance(MessageDispatcher::class);

    expect(fn () => app(MessageDispatcher::class)->dispatch(Message::query()->first()))
        ->toThrow(RuntimeException::class);

    expect(FakeDriver::$sent)->toBe([])
        ->and(Message::query()->first()->status)->toBe('deferred');
});

it('a takarító parancs törli a lejárt és a lezárt üzenetek csatolmányait', function () {
    $driver = new class implements SendingDriver
    {
        public function send(Message $message): DispatchResult
        {
            return DispatchResult::failed('Postal hiba: 503', 451, 'try later');
        }

        public function capabilities(): DriverCapabilities
        {
            return new DriverCapabilities(name: 'flaky');
        }

        public function health(): DriverHealth
        {
            return new DriverHealth(healthy: true, detail: 'ok');
        }
    };
    app()->instance(SendingDriver::class, $driver);

    sendWith([attachment(jpegBytes())])->assertAccepted();
    sendWith([attachment(jpegBytes())])->assertAccepted();
    sendWith([attachment(jpegBytes())])->assertAccepted();

    [$expired, $closed, $pending] = Message::query()->orderBy('id')->get()->all();

    MessageAttachment::query()->where('message_uuid', $expired->uuid)->update(['expires_at' => now()->subMinute()]);
    // A modellt megkerülő állapotváltás (pl. kézi javítás): a hook nem fut, a parancs fogja meg.
    DB::table('messages')->where('id', $closed->id)->update(['status' => 'delivered']);

    $this->artisan('messages:purge-attachments')->assertSuccessful();

    // A parancs tenantonként fut, és a végén elengedi a kontextust.
    app(WorkspaceContext::class)->set($this->workspace);

    expect(MessageAttachment::query()->pluck('message_uuid')->all())->toBe([$pending->uuid]);
});

it('az Art. 17 törlés a csatolmányt is viszi', function () {
    $driver = new class implements SendingDriver
    {
        public function send(Message $message): DispatchResult
        {
            return DispatchResult::failed('Postal hiba: 503', 451, 'try later');
        }

        public function capabilities(): DriverCapabilities
        {
            return new DriverCapabilities(name: 'flaky');
        }

        public function health(): DriverHealth
        {
            return new DriverHealth(healthy: true, detail: 'ok');
        }
    };
    app()->instance(SendingDriver::class, $driver);

    sendWith([attachment(jpegBytes())])->assertAccepted();
    expect(MessageAttachment::query()->count())->toBe(1);

    // N5: a riport a csatolmányt is megszámolja (az operátornak látnia kell, mi történt).
    $this->artisan('logs:redact-subject', ['email' => 'fan@example.test'])
        ->expectsOutputToContain('csatolmány: 1')
        ->assertSuccessful();
    app(WorkspaceContext::class)->set($this->workspace);

    expect(MessageAttachment::query()->count())->toBe(0);
});

it('az esemény nem hordoz csatolmány-tartalmat', function () {
    sendWith([attachment(jpegBytes())])->assertAccepted();

    MessageEvent::query()->get()->each(function (MessageEvent $event) {
        expect(json_encode($event->payload))->not->toContain('content');
    });
});

// --- S2: a csatolmány-sorok az `accepted` átmenettel ATOMIKUSAN íródnak -------------------

it('ha a csatolmány nem írható le, a levél nem marad accepted: rejected, a kulcs felszabadul', function () {
    $fail = true;
    MessageAttachment::creating(function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('disk full');
        }
    });

    sendWith([attachment(jpegBytes())], [], 'jm-atomic-1')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'ATTACHMENT_STORE_FAILED')
        ->assertHeader('Retry-After');

    $message = Message::query()->first();

    expect($message->status)->toBe('rejected')
        ->and($message->reason)->toBe('ATTACHMENT_STORE_FAILED')
        ->and($message->idempotency_key)->toBeNull()
        ->and($message->gate_decided_at)->toBeNull()
        ->and(MessageAttachment::query()->count())->toBe(0)
        ->and(FakeDriver::$sent)->toBe([])
        ->and(MessageEvent::query()->where('message_uuid', $message->uuid)->pluck('type')->all())->toBe(['rejected']);

    // Ugyanazzal a kulccsal az újrapróbálás most kimegy.
    $fail = false;
    sendWith([attachment(jpegBytes())], [], 'jm-atomic-1')->assertAccepted();

    expect(FakeDriver::$sent)->toHaveCount(1);
});

// --- S3: a típus-allowlist konfigurálható, a PDF alapból KI --------------------------------

it('a PDF alapból nem engedett (nincs még kimenő víruskereső)', function (?string $type) {
    sendWith([attachment(pdfBytes(), 'szamla.pdf', $type)])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'UNSUPPORTED_ATTACHMENT_TYPE');

    expect(config('sending.attachments.allowed_types'))->not->toContain('application/pdf');
})->with(['deklarálva' => ['application/pdf'], 'deklaráció nélkül' => [null]]);

it('az allowlist szűkíthető: kikapcsolt típus UNSUPPORTED_ATTACHMENT_TYPE', function () {
    config(['sending.attachments.allowed_types' => ['image/jpeg']]);

    sendWith([attachment(pngBytes(), 'kep.png', 'image/png')])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'UNSUPPORTED_ATTACHMENT_TYPE');

    sendWith([attachment(jpegBytes())])->assertAccepted();
});

it('az allowlist a katalóguson kívüli típust bekapcsolva sem enged', function () {
    // Az env-ből jövő lista csak SZŰKÍT: ismeretlen típushoz nincs tartalom-szippantás.
    config(['sending.attachments.allowed_types' => ['image/jpeg', 'text/html']]);

    sendWith([attachment('<html></html>', 'a.html', 'text/html')])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'UNSUPPORTED_ATTACHMENT_TYPE');
});

it('az alapértelmezett allowlist a config-fájlból: képek, PDF nélkül', function () {
    expect(configFileWith('sending', ['SENDING_ATTACHMENT_TYPES' => null])['attachments']['allowed_types'])
        ->toBe(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
        ->and(configFileWith('sending', ['SENDING_ATTACHMENT_TYPES' => 'image/jpeg, Application/PDF'])['attachments']['allowed_types'])
        ->toBe(['image/jpeg', 'application/pdf']);
});

// --- S4: workspace-enkénti függő-bájt korlát, ütemezés ≤ 24 óra csatolmánnyal --------------

/** Driver, ami mindig átmeneti hibát ad — a csatolmány így FÜGGŐBEN marad. */
function stickyDriver(): SendingDriver
{
    return new class implements SendingDriver
    {
        public function send(Message $message): DispatchResult
        {
            return DispatchResult::failed('Postal hiba: 503', 451, 'try later');
        }

        public function capabilities(): DriverCapabilities
        {
            return new DriverCapabilities(name: 'sticky');
        }

        public function health(): DriverHealth
        {
            return new DriverHealth(healthy: true, detail: 'ok');
        }
    };
}

it('a workspace függő csatolmány-bájtjai korlátosak: átmeneti ATTACHMENT_PENDING_LIMIT', function () {
    config(['sending.attachments.max_pending_bytes' => 5000]);
    app()->instance(SendingDriver::class, stickyDriver());

    sendWith([attachment(jpegBytes(2000))])->assertAccepted();
    sendWith([attachment(jpegBytes(2000))])->assertAccepted();

    sendWith([attachment(jpegBytes(2000))], [], 'jm-pending-3')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'ATTACHMENT_PENDING_LIMIT')
        ->assertHeader('Retry-After');

    $rejected = Message::query()->where('reason', 'ATTACHMENT_PENDING_LIMIT')->sole();

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->idempotency_key)->toBeNull()
        ->and(MessageAttachment::query()->sum('size'))->toBe(4000);

    // Az egyik függő levél lezárul → a bájtjai törlődnek → ugyanaz a kulcs most átmegy.
    Message::query()->where('status', 'deferred')->first()->forceFill(['status' => 'failed'])->save();

    sendWith([attachment(jpegBytes(2000))], [], 'jm-pending-3')->assertAccepted();
});

it('a függő-bájt korlát a config-fájlból: 200 MB alapérték', function () {
    expect(configFileWith('sending', ['SENDING_ATTACHMENT_MAX_PENDING_BYTES' => null])['attachments']['max_pending_bytes'])
        ->toBe(200 * 1024 * 1024)
        ->and(configFileWith('sending', ['SENDING_ATTACHMENT_MAX_SCHEDULE_HOURS' => null])['attachments']['max_schedule_hours'])
        ->toBe(24);
});

it('csatolmánnyal legfeljebb 24 órával előre ütemezhető', function () {
    sendWith([attachment(jpegBytes())], ['o:deliverytime' => now()->addHours(25)->toRfc2822String()])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'DELIVERYTIME_TOO_FAR');

    sendWith([attachment(jpegBytes())], ['o:deliverytime' => now()->addHours(23)->toRfc2822String()])
        ->assertAccepted();

    // Csatolmány nélkül a 7 napos korlát változatlan.
    sendWith([], ['o:deliverytime' => now()->addDays(3)->toRfc2822String()])->assertAccepted();
});

// --- S5: a `claim()` javítás regressziós tesztjei -----------------------------------------

it('az újra elbukó újrapróbálás deferred marad next_attempt_at-tel, a keret végén failed, a csatolmány törlődik', function () {
    app()->instance(SendingDriver::class, stickyDriver());
    app()->forgetInstance(MessageDispatcher::class);

    sendWith([attachment(jpegBytes())])->assertAccepted();

    $dispatcher = app(MessageDispatcher::class);

    // A MÁSODIK kísérlet (egy `deferred` levél újrapróbálása) is elbukik: a sor NEM ragad
    // `sending`-ben, hanem `deferred`, újabb `next_attempt_at`-tel.
    $dispatcher->dispatch(Message::query()->first());
    $message = Message::query()->first();

    expect($message->status)->toBe('deferred')
        ->and($message->attempts)->toBe(2)
        ->and($message->next_attempt_at)->not->toBeNull()
        ->and($message->next_attempt_at->isFuture())->toBeTrue()
        ->and(MessageAttachment::query()->count())->toBe(1);

    $guard = 0;

    while (($message = Message::query()->first())->status === 'deferred' && $guard++ < 20) {
        $dispatcher->dispatch($message);
    }

    $max = app(RetryPolicy::class)->maxAttempts();

    expect($message->status)->toBe('failed')
        ->and($message->attempts)->toBe($max + 1)
        ->and($message->next_attempt_at)->toBeNull()
        ->and(MessageAttachment::query()->count())->toBe(0)
        ->and(MessageEvent::query()->where('type', 'failed')->count())->toBe(1);
});

it('két dispatcher ugyanazon a levélen: a driver EGYSZER hívódik', function () {
    app()->instance(SendingDriver::class, stickyDriver());
    app()->forgetInstance(MessageDispatcher::class);

    sendWith([attachment(jpegBytes())])->assertAccepted();

    $stale = Message::query()->first();   // a második futás saját, régi példánya
    $first = Message::query()->first();

    $driver = new class implements SendingDriver
    {
        public int $calls = 0;

        /** @var (callable(): void)|null */
        public $whileSending = null;

        public function send(Message $message): DispatchResult
        {
            $this->calls++;

            if ($this->whileSending !== null) {
                ($this->whileSending)();
            }

            return DispatchResult::delivered('once-'.$message->uuid);
        }

        public function capabilities(): DriverCapabilities
        {
            return new DriverCapabilities(name: 'counting');
        }

        public function health(): DriverHealth
        {
            return new DriverHealth(healthy: true, detail: 'ok');
        }
    };

    app()->instance(SendingDriver::class, $driver);
    app()->forgetInstance(MessageDispatcher::class);
    $a = app()->make(MessageDispatcher::class);
    app()->forgetInstance(MessageDispatcher::class);
    $b = app()->make(MessageDispatcher::class);

    // A második dispatcher PONTOSAN akkor fut, amikor az első a drivernél tart.
    $driver->whileSending = function () use ($b, $stale, $driver): void {
        $driver->whileSending = null;
        $b->dispatch($stale);
    };

    $a->dispatch($first);
    // ...és utána még egyszer, a régi (még `deferred`-et hívő) példánnyal.
    $b->dispatch($stale);

    expect($driver->calls)->toBe(1)
        ->and(Message::query()->first()->status)->toBe('delivered')
        ->and(MessageAttachment::query()->count())->toBe(0);
});

// --- S6: batch-korlát 25 MB és a memória-lábnyom --------------------------------------------

it('a batch-korlát alapértéke 25 MB', function () {
    expect(configFileWith('sending', ['SENDING_ATTACHMENT_MAX_BATCH_BYTES' => null])['attachments']['max_batch_bytes'])
        ->toBe(25 * 1024 * 1024);
});

it('a csatolmányos batch memóriája lineáris: 1 MB × 10 címzett DKIM-mel 48 MB alatt marad', function () {
    $this->domain->forceFill([
        'dkim_selector' => 'vm202609',
        'dkim_private_key' => file_get_contents(base_path('tests/Fixtures/dkim-test-key.pem')),
        'dkim_public_key' => file_get_contents(base_path('tests/Fixtures/dkim-test-key.pub.pem')),
    ])->save();

    $recipients = array_map(fn (int $i) => "fan{$i}@example.test", range(1, 10));
    // Véletlen (nem tömöríthető, nem internált) 1 MB-os JPEG-fejlécű tartalom.
    $payload = [['filename' => 'kep.jpg', 'content_type' => 'image/jpeg', 'content' => base64_encode("\xFF\xD8\xFF".random_bytes(1024 * 1024))]];

    gc_collect_cycles();
    $base = memory_get_usage();
    memory_reset_peak_usage();

    sendWith($payload, ['to' => $recipients])->assertAccepted();

    $peak = memory_get_peak_usage() - $base;

    // Címzettenként egy aláírt MIME (~1,4 MB base64) él egyszerre — a négyzetes másolás
    // (minden címzettnél a teljes, egyre nagyobb tömb) ennek a sokszorosa lenne.
    expect(FakeDriver::$sent[0]['attachments'])->toHaveCount(1)
        ->and($peak)->toBeLessThan(48 * 1024 * 1024, sprintf('csúcs: %.1f MB', $peak / 1048576));
});

// --- N2: a batch-szorzatot már a BECSÜLT méreten nézzük (a dekódolás előtt) ---------------

it('a batch-szorzat a becsült méreten is: a túl nagy batch nem dekódolódik', function () {
    config(['sending.attachments.max_batch_bytes' => 5000]);

    // Érvénytelen base64 (`!`): ha a dekódolás elindulna, INVALID_ATTACHMENT lenne a válasz.
    sendWith([['filename' => 'a.jpg', 'content' => str_repeat('!', 4000)]], ['to' => ['a@example.test', 'b@example.test']])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'ATTACHMENT_TOO_LARGE');
});
